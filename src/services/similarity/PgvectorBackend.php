<?php

namespace justinholtweb\spectacles\services\similarity;

use Craft;
use yii\db\Expression;

/**
 * pgvector-backed similarity. Uses a dedicated `spectacles_imagevectors`
 * table with a real `vector` column. Cosine distance via the `<=>` operator
 * is computed in C — orders of magnitude faster than the PHP scan and lets
 * the user add an HNSW index for production scale.
 *
 * Falls back gracefully (isAvailable() returns false) on non-Postgres or
 * when the extension/table is missing — the Similarity service then picks
 * ScanBackend instead.
 */
class PgvectorBackend implements SimilarityBackend
{
    private const TABLE = '{{%spectacles_imagevectors}}';

    public function search(array $vector, int $limit, float $minScore, array $excludeAssetIds = []): array
    {
        $db = Craft::$app->db;
        $literal = $this->vectorLiteral($vector);

        // Cosine distance in pgvector ranges 0 (identical) to 2 (opposite);
        // similarity = 1 - distance.
        $maxDistance = 1 - $minScore;

        $query = (new \craft\db\Query())
            ->select([
                'assetId',
                'distance' => new Expression("embedding <=> :qv::vector", [':qv' => $literal]),
            ])
            ->from(self::TABLE)
            ->where(new Expression("embedding <=> :qv2::vector <= :maxDist", [
                ':qv2' => $literal,
                ':maxDist' => $maxDistance,
            ]))
            ->orderBy(new Expression("embedding <=> :qv3::vector", [':qv3' => $literal]))
            ->limit($limit);

        if ($excludeAssetIds) {
            $query->andWhere(['not in', 'assetId', $excludeAssetIds]);
        }

        $rows = $query->all($db);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'assetId' => (int)$row['assetId'],
                'score' => 1.0 - (float)$row['distance'],
            ];
        }
        return $out;
    }

    public function index(int $assetId, array $vector, string $model): void
    {
        $db = Craft::$app->db;
        $literal = $this->vectorLiteral($vector);
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $db->createCommand()->upsert(
            self::TABLE,
            [
                'assetId' => $assetId,
                'embedding' => new Expression(':v::vector', [':v' => $literal]),
                'embeddingModel' => $model,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ],
            [
                'embedding' => new Expression(':v2::vector', [':v2' => $literal]),
                'embeddingModel' => $model,
                'dateUpdated' => $now,
            ]
        )->execute();
    }

    public function deleteForAsset(int $assetId): void
    {
        Craft::$app->db->createCommand()
            ->delete(self::TABLE, ['assetId' => $assetId])
            ->execute();
    }

    public function isAvailable(): bool
    {
        $db = Craft::$app->db;
        if ($db->driverName !== 'pgsql') {
            return false;
        }
        if (!$db->tableExists(self::TABLE)) {
            return false;
        }
        try {
            $hasExt = $db->createCommand("SELECT 1 FROM pg_extension WHERE extname = 'vector'")->queryScalar();
            return (bool)$hasExt;
        } catch (\Throwable) {
            return false;
        }
    }

    public function name(): string
    {
        return 'pgvector';
    }

    /**
     * @param float[] $vector
     */
    private function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(
            fn(float $v): string => rtrim(rtrim(sprintf('%.8f', $v), '0'), '.') ?: '0',
            $vector,
        )) . ']';
    }
}
