<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\Metadata;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use justinholtweb\spectacles\tests\_support\AssetHelper;

/**
 * Metadata::store() is the write path every analysis funnels through, and it
 * has to be idempotent — re-analysing an asset must update the existing row
 * rather than pile up duplicates.
 */
class MetadataServiceTest extends Unit
{
    use AssetHelper;

    private Metadata $metadata;

    protected function _before(): void
    {
        $this->metadata = new Metadata();
    }

    private function analysis(string $description = 'A red car'): AnalysisResult
    {
        return new AnalysisResult(
            description: $description,
            tags: ['car', 'red'],
            objects: ['vehicle'],
            colors: ['red'],
            raw: ['ok' => true],
            provider: 'openai',
            model: 'gpt-4o-mini',
        );
    }

    public function testStoreCreatesARecord(): void
    {
        $asset = $this->createImageAsset();

        $record = $this->metadata->store($asset->id, $this->analysis(), [0.1, 0.2], 'text-embedding-3-small');

        $this->assertNotNull($record->id);
        $this->assertSame('A red car', $record->description);
        $this->assertSame(['car', 'red'], $record->tags);
        $this->assertSame(['vehicle'], $record->objects);
        $this->assertSame(['red'], $record->colors);
        $this->assertSame('openai', $record->visionProvider);
        $this->assertSame('gpt-4o-mini', $record->visionModel);
        $this->assertSame('text-embedding-3-small', $record->embeddingModel);
        $this->assertNotNull($record->analyzedAt);
    }

    public function testStoreUpdatesTheExistingRecordInsteadOfDuplicating(): void
    {
        $asset = $this->createImageAsset();

        $first = $this->metadata->store($asset->id, $this->analysis('First pass'), [0.1], 'model-a');
        $second = $this->metadata->store($asset->id, $this->analysis('Second pass'), [0.9], 'model-b');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Second pass', $second->description);
        $this->assertSame('model-b', $second->embeddingModel);

        $count = ImageMetadata::find()->where(['assetId' => $asset->id])->count();
        $this->assertEquals(1, $count);
    }

    public function testStoreAcceptsANullEmbedding(): void
    {
        $asset = $this->createImageAsset();

        $record = $this->metadata->store($asset->id, $this->analysis(), null, null);

        $this->assertNull($record->embedding);
        $this->assertNull($record->embeddingModel);
    }

    public function testFindByAssetIdReturnsTheStoredRecord(): void
    {
        $asset = $this->createImageAsset();
        $this->metadata->store($asset->id, $this->analysis(), [0.5], 'model');

        $found = $this->metadata->findByAssetId($asset->id);

        $this->assertNotNull($found);
        $this->assertSame($asset->id, (int)$found->assetId);
    }

    public function testFindByAssetIdReturnsNullWhenThereIsNothingStored(): void
    {
        $asset = $this->createImageAsset();

        $this->assertNull($this->metadata->findByAssetId($asset->id));
    }

    public function testAnalyzeAssetRejectsNonImages(): void
    {
        $asset = $this->createImageAsset();
        // Force a non-image kind without touching the file on disk.
        $asset->kind = \craft\elements\Asset::KIND_PDF;

        $this->expectException(\RuntimeException::class);
        $this->metadata->analyzeAsset($asset);
    }
}
