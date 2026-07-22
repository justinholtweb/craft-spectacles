<?php

namespace justinholtweb\spectacles\services\similarity;

use justinholtweb\spectacles\records\ImageMetadata;

/**
 * Default backend: load every record's JSON-encoded embedding and compute
 * cosine similarity in PHP. Fine up to a few thousand assets; switch to
 * PgvectorBackend for larger libraries.
 */
class ScanBackend implements SimilarityBackend
{
    public function search(array $vector, int $limit, float $minScore, array $excludeAssetIds = []): array
    {
        /** @var ImageMetadata[] $records */
        $records = ImageMetadata::find()
            ->where(['not', ['embedding' => null]])
            ->all();

        $scored = [];
        foreach ($records as $record) {
            if (in_array($record->assetId, $excludeAssetIds, true)) {
                continue;
            }
            if (!is_array($record->embedding) || count($record->embedding) !== count($vector)) {
                continue;
            }
            $score = $this->cosine($vector, $record->embedding);
            if ($score < $minScore) {
                continue;
            }
            $scored[] = ['assetId' => (int)$record->assetId, 'score' => $score];
        }

        usort($scored, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    public function index(int $assetId, array $vector, string $model): void
    {
        // No-op — embeddings live in the spectacles_imagemetadata table
        // already, written by the Metadata service.
    }

    public function deleteForAsset(int $assetId): void
    {
        // No-op for the same reason.
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'scan';
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
