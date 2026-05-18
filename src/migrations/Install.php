<?php

namespace justinholtweb\spectacles\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%spectacles_imagemetadata}}';

        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->notNull(),
                'description' => $this->text(),
                'tags' => $this->json(),
                'colors' => $this->json(),
                'objects' => $this->json(),
                'rawResponse' => $this->json(),
                'embedding' => $this->json(),
                'embeddingModel' => $this->string(64),
                'visionProvider' => $this->string(32),
                'visionModel' => $this->string(64),
                'analyzedAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $table, ['assetId'], true);
            $this->addForeignKey(null, $table, ['assetId'], '{{%assets}}', ['id'], 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%spectacles_imagemetadata}}');
        return true;
    }
}
