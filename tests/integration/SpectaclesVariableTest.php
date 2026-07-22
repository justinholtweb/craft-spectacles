<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\tests\_support\AssetHelper;
use justinholtweb\spectacles\variables\SpectaclesVariable;

/**
 * The Twig-facing API: craft.spectacles.*
 */
class SpectaclesVariableTest extends Unit
{
    use AssetHelper;

    private SpectaclesVariable $variable;

    protected function _before(): void
    {
        $this->variable = new SpectaclesVariable();
    }

    public function testVariableIsRegisteredOnCraft(): void
    {
        $behaviors = Craft::$app->view->getTwig()->getGlobals();

        $this->assertArrayHasKey('craft', $behaviors);
        $this->assertInstanceOf(
            SpectaclesVariable::class,
            $behaviors['craft']->spectacles
        );
    }

    public function testMetadataForReturnsTheStoredRecord(): void
    {
        $asset = $this->createImageAsset();
        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'a windmill',
            'embedding' => [1.0, 0.0],
            'embeddingModel' => 'test-model',
        ]);
        $record->save();

        $found = $this->variable->metadataFor($asset);

        $this->assertNotNull($found);
        $this->assertSame('a windmill', $found->description);
    }

    public function testMetadataForReturnsNullWhenUnanalyzed(): void
    {
        $asset = $this->createImageAsset();

        $this->assertNull($this->variable->metadataFor($asset));
    }

    public function testSimilarDelegatesToTheSimilarityService(): void
    {
        $source = $this->createImageAsset();
        (new ImageMetadata([
            'assetId' => $source->id,
            'description' => 'source',
            'embedding' => [1.0, 0.0],
            'embeddingModel' => 'test-model',
        ]))->save();

        $neighbour = $this->createImageAsset();
        (new ImageMetadata([
            'assetId' => $neighbour->id,
            'description' => 'neighbour',
            'embedding' => [0.99, 0.01],
            'embeddingModel' => 'test-model',
        ]))->save();

        $results = $this->variable->similar($source);

        $ids = array_map(fn(array $row): int => $row['asset']->id, $results);
        $this->assertContains($neighbour->id, $ids);
        $this->assertNotContains($source->id, $ids);
    }

    public function testSimilarReturnsEmptyArrayForAnUnanalyzedAsset(): void
    {
        $asset = $this->createImageAsset();

        $this->assertSame([], $this->variable->similar($asset));
    }
}
