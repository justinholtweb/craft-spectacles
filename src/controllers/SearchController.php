<?php

namespace justinholtweb\spectacles\controllers;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\web\Controller;
use craft\web\UploadedFile;
use justinholtweb\spectacles\helpers\RateLimit;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

class SearchController extends Controller
{
    private const MAX_LIMIT = 100;

    protected array|int|bool $allowAnonymous = ['upload', 'similar'];
    public $enableCsrfValidation = false;

    /**
     * Visitor uploads an image; returns a JSON list of similar assets.
     */
    public function actionUpload(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->allowPublicSearch) {
            throw new ForbiddenHttpException('Public search is disabled.');
        }
        $this->enforceRateLimit($settings);

        $file = UploadedFile::getInstanceByName('image');
        if (!$file) {
            throw new BadRequestHttpException('No image uploaded.');
        }

        if ($file->size > $settings->maxVisitorUploadKb * 1024) {
            throw new BadRequestHttpException('Image exceeds the configured size limit.');
        }

        $mime = mime_content_type($file->tempName) ?: 'image/jpeg';
        if (!str_starts_with($mime, 'image/')) {
            throw new BadRequestHttpException('Uploaded file is not an image.');
        }

        $imageData = file_get_contents($file->tempName);
        if ($imageData === false) {
            throw new BadRequestHttpException('Could not read uploaded file.');
        }

        try {
            $result = Plugin::getInstance()->similarity->similarToImage(
                $imageData,
                $mime,
                $this->intParam('limit'),
                $settings->getPublicVolumeIds(),
            );
        } catch (Throwable $e) {
            Craft::error('Spectacles upload search failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson(['error' => 'Search failed.'])->setStatusCode(500);
        }

        return $this->asJson([
            'analysis' => [
                'description' => $result['analysis']->description,
                'tags' => $result['analysis']->tags,
                'objects' => $result['analysis']->objects,
                'colors' => $result['analysis']->colors,
            ],
            'results' => array_map(fn(array $row): array => $this->transformResult($row), $result['results']),
        ]);
    }

    /**
     * Return assets similar to a given asset id.
     */
    public function actionSimilar(int $assetId): Response
    {
        $this->requireAcceptsJson();

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->allowPublicSearch) {
            throw new ForbiddenHttpException('Public search is disabled.');
        }
        $this->enforceRateLimit($settings);

        // Only an image in a public volume can be asked about, and only public images come back.
        // An asset anywhere else is "not found", so its existence isn't confirmed either.
        $publicVolumeIds = $settings->getPublicVolumeIds();
        $asset = $publicVolumeIds === [] ? null : Asset::find()->id($assetId)->volumeId($publicVolumeIds)->one();
        if (!$asset) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $results = Plugin::getInstance()->similarity->similarToAsset(
            $asset,
            $this->intParam('limit'),
            $publicVolumeIds,
        );

        return $this->asJson([
            'results' => array_map(fn(array $row): array => $this->transformResult($row), $results),
        ]);
    }

    private function transformResult(array $row): array
    {
        /** @var Asset $asset */
        $asset = $row['asset'];
        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'url' => $asset->getUrl(),
            'thumbUrl' => $asset->getUrl(['width' => 320, 'height' => 320, 'mode' => 'crop']),
            'score' => $row['score'],
            'description' => $row['metadata']->description,
            'tags' => $row['metadata']->tags ?? [],
        ];
    }

    private function intParam(string $name): ?int
    {
        $value = Craft::$app->request->getParam($name);
        if ($value === null || $value === '') {
            return null;
        }
        return max(1, min(self::MAX_LIMIT, (int)$value));
    }

    /**
     * Per-address rate limit for the anonymous endpoints, under a site-wide ceiling. Each call fans
     * out to a paid vision/embedding API, so this caps how fast visitors can run up the bill.
     * Disabled when the limit is 0.
     *
     * Through helpers\RateLimit since 5.1.0: the old window keyed on `getUserIP()`, which a forged
     * `X-Forwarded-For` resets, and read-then-wrote without a lock, so parallel requests all got in.
     */
    private function enforceRateLimit(Settings $settings): void
    {
        $limit = $settings->publicSearchRateLimit;
        if ($limit <= 0) {
            return;
        }

        if (!RateLimit::allowWindow('search', $limit, max(1, $settings->publicSearchRateWindow))) {
            throw new TooManyRequestsHttpException('Search rate limit exceeded. Please slow down.');
        }
    }
}
