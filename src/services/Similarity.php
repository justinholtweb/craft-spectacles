<?php

namespace justinholtweb\spectacles\services;

use Craft;
use craft\elements\Asset;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use yii\base\Component;

/**
 * Computes similarity between a query (asset or arbitrary text/image) and
 * stored metadata records. Cosine similarity is computed in PHP — this is
 * fine up to a few thousand assets. For larger libraries, swap this service
 * for a pgvector or Pinecone-backed implementation.
 */
class Similarity extends Component
{
    /**
     * Find assets similar to a stored asset.
     *
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
     * Find assets similar to a free-form text query.
     *
     * @return array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     */
    public function similarToText(string $text, ?int $limit = null): array
    {
        $vision = Plugin::getInstance()->vision;
        $embedded = $vision->embedText($text);
        return $this->similarToVector($embedded['vector'], $limit);
    }

    /**
     * Find assets similar to a raw uploaded image. Runs full vision +
     * embedding on the image first, then ranks.
     *
     * @return array{
     *     analysis: \justinholtweb\spectacles\services\vision\AnalysisResult,
     *     results: array<int, array{asset: Asset, score: float, metadata: ImageMetadata}>
     * }
     */
    public function similarToImage(string $imageData, string $mimeType, ?int $limit = null): array
    {
        $vision = Plugin::getInstance()->vision;
        $analysis = $vision->analyze($imageData, $mimeType);

        $results = [];
        $embeddable = $analysis->embeddableText();
        if ($embeddable !== '') {
            $embedded = $vision->embedText($embeddable);
            $results = $this->similarToVector($embedded['vector'], $limit);
        }

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

        $records = ImageMetadata::find()
            ->where(['not', ['embedding' => null]])
            ->all();

        $scored = [];
        foreach ($records as $record) {
            if (in_array($record->assetId, $excludeAssetIds, true)) {
                continue;
            }
            if (!is_array($record->embedding) || !$record->embedding) {
                continue;
            }
            $score = $this->cosine($vector, $record->embedding);
            if ($score < $minScore) {
                continue;
            }
            $scored[] = ['record' => $record, 'score' => $score];
        }

        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $limit);

        $assetIds = array_map(fn(array $row): int => $row['record']->assetId, $scored);
        if (!$assetIds) {
            return [];
        }

        $assets = Asset::find()->id($assetIds)->indexBy('id')->all();

        $out = [];
        foreach ($scored as $row) {
            $asset = $assets[$row['record']->assetId] ?? null;
            if (!$asset) {
                continue;
            }
            $out[] = [
                'asset' => $asset,
                'score' => round($row['score'], 4),
                'metadata' => $row['record'],
            ];
        }

        return $out;
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosine(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        if ($magA === 0.0 || $magB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }
}
