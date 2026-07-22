<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\similarity\ScanBackend;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\tests\_support\AssetHelper;

/**
 * The Similarity service picks a backend and turns raw hits into
 * asset/score/metadata rows.
 */
class SimilarityServiceTest extends Unit
{
    use AssetHelper;

    private Similarity $similarity;

    protected function _before(): void
    {
        // A fresh instance each time — the service memoises its backend.
        $this->similarity = new Similarity();

        Plugin::getInstance()->setSettings([
            'vectorIndex' => Settings::INDEX_AUTO,
            'defaultResultLimit' => 12,
            'minSimilarityScore' => 0.5,
        ]);
    }

    private function seed(array $embedding, string $description = 'seeded'): int
    {
        $asset = $this->createImageAsset();

        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => $description,
            'embedding' => $embedding,
            'embeddingModel' => 'test-model',
        ]);
        if (!$record->save()) {
            $this->fail('Could not seed metadata: ' . json_encode($record->getErrors()));
        }

        return $asset->id;
    }

    public function testFallsBackToTheScanBackendOnMysql(): void
    {
        // pgvector is unavailable on MySQL/MariaDB, so AUTO must degrade.
        $this->assertInstanceOf(ScanBackend::class, $this->similarity->backend());
        $this->assertSame('scan', $this->similarity->backend()->name());
    }

    public function testExplicitScanPreferenceIsHonoured(): void
    {
        Plugin::getInstance()->setSettings(['vectorIndex' => Settings::INDEX_SCAN]);

        $this->assertInstanceOf(ScanBackend::class, (new Similarity())->backend());
    }

    public function testSimilarToVectorHydratesAssetsAndMetadata(): void
    {
        $assetId = $this->seed([1.0, 0.0, 0.0], 'a red car');

        $results = $this->similarity->similarToVector([1.0, 0.0, 0.0]);

        $this->assertNotEmpty($results);
        $this->assertSame($assetId, $results[0]['asset']->id);
        $this->assertSame('a red car', $results[0]['metadata']->description);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 1e-4);
    }

    public function testSimilarToVectorRoundsTheScore(): void
    {
        $this->seed([0.9, 0.1, 0.0]);

        $results = $this->similarity->similarToVector([1.0, 0.0, 0.0]);

        $this->assertNotEmpty($results);
        $score = $results[0]['score'];
        $this->assertSame(round($score, 4), $score);
    }

    public function testSimilarToVectorAppliesTheConfiguredMinimumScore(): void
    {
        $this->seed([0.0, 1.0, 0.0]);

        Plugin::getInstance()->setSettings(['minSimilarityScore' => 0.9]);

        $this->assertSame([], (new Similarity())->similarToVector([1.0, 0.0, 0.0]));
    }

    public function testSimilarToVectorAppliesTheConfiguredDefaultLimit(): void
    {
        $this->seed([1.0, 0.0, 0.0]);
        $this->seed([0.99, 0.01, 0.0]);
        $this->seed([0.98, 0.02, 0.0]);

        Plugin::getInstance()->setSettings(['defaultResultLimit' => 2, 'minSimilarityScore' => 0.0]);

        $this->assertCount(2, (new Similarity())->similarToVector([1.0, 0.0, 0.0]));
    }

    public function testSimilarToAssetExcludesTheSourceAsset(): void
    {
        $sourceId = $this->seed([1.0, 0.0, 0.0], 'source');
        $otherId = $this->seed([0.95, 0.05, 0.0], 'other');

        $source = \craft\elements\Asset::find()->id($sourceId)->one();
        $results = (new Similarity())->similarToAsset($source);

        $ids = array_map(fn(array $row): int => $row['asset']->id, $results);
        $this->assertNotContains($sourceId, $ids);
        $this->assertContains($otherId, $ids);
    }

    public function testSimilarToAssetReturnsNothingWhenTheAssetHasNoMetadata(): void
    {
        $asset = $this->createImageAsset();

        $this->assertSame([], (new Similarity())->similarToAsset($asset));
    }

    public function testSimilarToAssetReturnsNothingWhenMetadataHasNoEmbedding(): void
    {
        $asset = $this->createImageAsset();
        $record = new ImageMetadata(['assetId' => $asset->id, 'description' => 'no vector']);
        $record->save();

        $this->assertSame([], (new Similarity())->similarToAsset($asset));
    }

    public function testHitsWithoutASurvivingAssetAreDroppedFromResults(): void
    {
        $keptId = $this->seed([1.0, 0.0, 0.0], 'kept');
        $goneId = $this->seed([1.0, 0.0, 0.0], 'gone');

        // Soft-delete leaves the metadata row (and its FK) intact while the
        // asset element query stops returning it.
        $gone = \craft\elements\Asset::find()->id($goneId)->one();
        \Craft::$app->getElements()->deleteElement($gone);

        $results = (new Similarity())->similarToVector([1.0, 0.0, 0.0]);

        $ids = array_map(fn(array $row): int => $row['asset']->id, $results);
        $this->assertContains($keptId, $ids);
        $this->assertNotContains($goneId, $ids);
    }
}
