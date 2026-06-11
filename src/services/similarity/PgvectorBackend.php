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

        // The `vector` column is dimension-unconstrained, so a library that
        // was partially re-indexed after a provider switch can hold vectors of
        // mixed dimensions. The `<=>` operator throws when the operands differ
        // in dimension, so we MUST filter to matching-dimension rows *before*
        // the operator is ever applied — otherwise a single stale row aborts
        // the whole query. A MATERIALIZED CTE forces that filter to run first,
        // mirroring the scan backend's "skip mismatches" behavior.
        $dim = count($vector);
        $limit = max(1, (int)$limit);

        $excludeSql = '';
        if ($excludeAssetIds) {
            $ids = implode(',', array_map('intval', $excludeAssetIds));
            $excludeSql = " AND \"assetId\" NOT IN ($ids)";
        }

        $sql = <<<SQL
            WITH candidates AS MATERIALIZED (
                SELECT "assetId", "embedding"
                FROM {{%spectacles_imagevectors}}
                WHERE vector_dims("embedding") = {$dim}{$excludeSql}
            )
            SELECT "assetId", ("embedding" <=> :qv1::vector) AS distance
            FROM candidates
            WHERE ("embedding" <=> :qv2::vector) <= :maxDist
            ORDER BY "embedding" <=> :qv3::vector
            LIMIT {$limit}
        SQL;

        $rows = $db->createCommand($sql, [
            ':qv1' => $literal,
            ':qv2' => $literal,
            ':qv3' => $literal,
            ':maxDist' => $maxDistance,
        ])->queryAll();

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
