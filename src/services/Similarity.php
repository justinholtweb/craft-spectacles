<?php

namespace justinholtweb\spectacles\services;

use craft\elements\Asset;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\similarity\PgvectorBackend;
use justinholtweb\spectacles\services\similarity\ScanBackend;
use justinholtweb\spectacles\services\similarity\SimilarityBackend;
use yii\base\Component;

/**
 * Similarity ranking. Resolves a {@see SimilarityBackend} based on settings
 * + DB capability and delegates search/index calls.
 */
class Similarity extends Component
{
    private ?SimilarityBackend $backend = null;

    public function backend(): SimilarityBackend
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        $settings = Plugin::getInstance()->getSettings();
        $pref = $settings->vectorIndex;

        if ($pref === Settings::INDEX_PGVECTOR || $pref === Settings::INDEX_AUTO) {
            $pgvector = new PgvectorBackend();
            if ($pgvector->isAvailable()) {
                return $this->backend = $pgvector;
            }
        }

        return $this->backend = new ScanBackend();
    }

    public function indexAsset(int $assetId, array $vector, string $model): void
    {
        $this->backend()->index($assetId, $vector, $model);
    }

    public function deleteForAsset(int $assetId): void
    {
        $this->backend()->deleteForAsset($assetId);
    }

    /**
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similarToAsset(Asset $asset, ?int $limit = null, ?array $volumeIds = null): array
    {
        $metadata = Plugin::getInstance()->metadata->findByAssetId($asset->id);
        if (!$metadata || !$metadata->embedding) {
            return [];
        }
        return $this->similarToVector($metadata->embedding, $limit, excludeAssetIds: [$asset->id], volumeIds: $volumeIds);
    }

    /**
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similarToText(string $text, ?int $limit = null): array
    {
        $vision = Plugin::getInstance()->vision;
        $result = $vision->embedText($text);
        return $this->similarToVector($result->vector, $limit);
    }

    /**
     * @return array{
     *     analysis: \justinholtweb\spectacles\services\vision\AnalysisResult,
     *     results: array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     * }
     */
    public function similarToImage(string $imageData, string $mimeType, ?int $limit = null, ?array $volumeIds = null): array
    {
        $vision = Plugin::getInstance()->vision;
        $analysis = $vision->analyze($imageData, $mimeType);

        $embedding = $vision->embedForImage($imageData, $mimeType, $analysis);
        $results = $embedding !== null
            ? $this->similarToVector($embedding->vector, $limit, volumeIds: $volumeIds)
            : [];

        return ['analysis' => $analysis, 'results' => $results];
    }

    /**
     * @param float[] $vector
     * @param int[] $excludeAssetIds
     * @param int[]|null $volumeIds Only assets in these volumes; null means any. The public
     *                              endpoints pass the public volumes — an empty array finds nothing.
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similarToVector(array $vector, ?int $limit = null, array $excludeAssetIds = [], ?array $volumeIds = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit ??= $settings->defaultResultLimit;
        $minScore = $settings->minSimilarityScore;

        if ($volumeIds === []) {
            return [];
        }

        // The index holds every analysed image, so a volume restriction is applied to the hits.
        // More are asked for than are wanted, so the restriction doesn't shorten the list.
        $hits = $this->backend()->search($vector, $volumeIds === null ? $limit : $limit * 5, $minScore, $excludeAssetIds);
        if (!$hits) {
            return [];
        }

        $assetIds = array_map(fn(array $h): int => $h['assetId'], $hits);
        $assetQuery = Asset::find()->id($assetIds)->indexBy('id');
        if ($volumeIds !== null) {
            $assetQuery->volumeId($volumeIds);
        }
        $assets = $assetQuery->all();
        /** @var array<int, ImageMetadata> $metadata */
        $metadata = ImageMetadata::find()->where(['assetId' => $assetIds])->indexBy('assetId')->all();

        $out = [];
        foreach ($hits as $hit) {
            $asset = $assets[$hit['assetId']] ?? null;
            $meta = $metadata[$hit['assetId']] ?? null;
            if (!$asset || !$meta) {
                continue;
            }
            $out[] = [
                'asset' => $asset,
                'score' => round($hit['score'], 4),
                'metadata' => $meta,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
