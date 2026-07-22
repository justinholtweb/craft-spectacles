<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\tests\_support\AssetHelper;

/**
 * The record leans on JSON columns for tags/objects/colors/embedding, so the
 * round-trip through the database is worth pinning down explicitly.
 */
class ImageMetadataRecordTest extends Unit
{
    use AssetHelper;

    public function testJsonColumnsRoundTrip(): void
    {
        $asset = $this->createImageAsset();

        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'A foggy ridge',
            'tags' => ['fog', 'ridge'],
            'objects' => ['mountain'],
            'colors' => ['grey'],
            'rawResponse' => ['choices' => [['index' => 0]]],
            'embedding' => [0.1, 0.2, 0.3],
            'embeddingModel' => 'text-embedding-3-small',
            'visionProvider' => 'openai',
            'visionModel' => 'gpt-4o-mini',
        ]);

        $this->assertTrue($record->save(), json_encode($record->getErrors()));

        $fresh = ImageMetadata::findOne(['assetId' => $asset->id]);

        $this->assertNotNull($fresh);
        $this->assertSame(['fog', 'ridge'], $fresh->tags);
        $this->assertSame(['mountain'], $fresh->objects);
        $this->assertSame(['grey'], $fresh->colors);
        $this->assertSame(['choices' => [['index' => 0]]], $fresh->rawResponse);
        $this->assertEqualsWithDelta([0.1, 0.2, 0.3], $fresh->embedding, 1e-9);
    }

    public function testNullEmbeddingIsPreserved(): void
    {
        $asset = $this->createImageAsset();

        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'No embedding yet',
        ]);
        $this->assertTrue($record->save(), json_encode($record->getErrors()));

        $fresh = ImageMetadata::findOne(['assetId' => $asset->id]);

        $this->assertNull($fresh->embedding);
    }

    public function testOneRecordPerAssetIsEnforced(): void
    {
        $asset = $this->createImageAsset();

        $first = new ImageMetadata(['assetId' => $asset->id, 'description' => 'first']);
        $this->assertTrue($first->save(), json_encode($first->getErrors()));

        // The install migration puts a unique index on assetId.
        $second = new ImageMetadata(['assetId' => $asset->id, 'description' => 'second']);

        $this->expectException(\yii\db\Exception::class);
        $second->save(false);
    }

    public function testDeletingTheAssetCascadesToMetadata(): void
    {
        $asset = $this->createImageAsset();

        $record = new ImageMetadata(['assetId' => $asset->id, 'description' => 'doomed']);
        $this->assertTrue($record->save(), json_encode($record->getErrors()));

        // Hard-delete so the FK's ON DELETE CASCADE actually fires.
        Craft::$app->getElements()->deleteElement($asset, true);

        $this->assertNull(ImageMetadata::findOne(['assetId' => $asset->id]));
    }
}
