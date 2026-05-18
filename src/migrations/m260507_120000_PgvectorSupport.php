<?php

namespace justinholtweb\spectacles\migrations;

use Craft;
use craft\db\Migration;

/**
 * Creates the spectacles_imagevectors table when running on Postgres with
 * the `vector` extension installed. Otherwise this is a no-op — the plugin
 * works fine on MySQL/MariaDB or Postgres-without-pgvector via the JSON
 * scan backend.
 */
class m260507_120000_PgvectorSupport extends Migration
{
    public function safeUp(): bool
    {
        if (Craft::$app->db->driverName !== 'pgsql') {
            return true;
        }

        $hasExtension = false;
        try {
            $hasExtension = (bool)Craft::$app->db
                ->createCommand("SELECT 1 FROM pg_extension WHERE extname = 'vector'")
                ->queryScalar();
        } catch (\Throwable $e) {
            Craft::warning("Spectacles: could not detect pgvector extension: {$e->getMessage()}", __METHOD__);
        }

        if (!$hasExtension) {
            Craft::info('Spectacles: pgvector extension not installed; skipping vector index table.', __METHOD__);
            return true;
        }

        $table = '{{%spectacles_imagevectors}}';
        if ($this->db->tableExists($table)) {
            return true;
        }

        // Use raw SQL — Yii's schema builder does not know the vector type.
        // Dimension is unconstrained so any provider's vector fits; users
        // who want HNSW or IVFFlat indexes can add them once their dim is
        // stable.
        $this->execute(<<<SQL
            CREATE TABLE {{%spectacles_imagevectors}} (
                "assetId" integer NOT NULL PRIMARY KEY REFERENCES {{%assets}}("id") ON DELETE CASCADE,
                "embedding" vector,
                "embeddingModel" varchar(64),
                "dateCreated" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "dateUpdated" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%spectacles_imagevectors}}');
        return true;
    }
}
