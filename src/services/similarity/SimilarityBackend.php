<?php

namespace justinholtweb\spectacles\services\similarity;

interface SimilarityBackend
{
    /**
     * @param float[] $vector
     * @param int[] $excludeAssetIds
     * @return array<int, array{assetId: int, score: float}>  Already sorted desc by score.
     */
    public function search(array $vector, int $limit, float $minScore, array $excludeAssetIds = []): array;

    /**
     * Persist (or update) a vector for an asset. JSON-only backends can no-op.
     *
     * @param float[] $vector
     */
    public function index(int $assetId, array $vector, string $model): void;

    public function deleteForAsset(int $assetId): void;

    public function isAvailable(): bool;

    public function name(): string;
}
