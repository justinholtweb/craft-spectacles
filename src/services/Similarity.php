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
    public function similarToAsset(Asset $asset, ?int $limit = null): array
    {
        $metadata = Plugin::getInstance()->metadata->findByAssetId($asset->id);
        if (!$metadata || !$metadata->embedding) {
            return [];
        }
        return $this->similarToVector($metadata->embedding, $limit, excludeAssetIds: [$asset->id]);
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
    public function similarToImage(string $imageData, string $mimeType, ?int $limit = null): array
    {
        $vision = Plugin::getInstance()->vision;
        $analysis = $vision->analyze($imageData, $mimeType);

        $embedding = $vision->embedForImage($imageData, $mimeType, $analysis);
        $results = $embedding !== null
            ? $this->similarToVector($embedding->vector, $limit)
            : [];

        return ['analysis' => $analysis, 'results' => $results];
    }

    /**
     * @param float[] $vector
     * @param int[] $excludeAssetIds
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similarToVector(array $vector, ?int $limit = null, array $excludeAssetIds = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit ??= $settings->defaultResultLimit;
        $minScore = $settings->minSimilarityScore;

        $hits = $this->backend()->search($vector, $limit, $minScore, $excludeAssetIds);
        if (!$hits) {
            return [];
        }

        $assetIds = array_map(fn(array $h): int => $h['assetId'], $hits);
        $assets = Asset::find()->id($assetIds)->indexBy('id')->all();
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
        }
        return $out;
    }
}
