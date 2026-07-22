<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\similarity\ScanBackend;
use justinholtweb\spectacles\tests\_support\AssetHelper;

/**
 * ScanBackend::search() reads every embedding out of the database and ranks in
 * PHP. The cosine maths is covered by the unit suite; this pins down the query
 * behaviour — filtering, ordering, and the guards around bad rows.
 */
class ScanBackendSearchTest extends Unit
{
    use AssetHelper;

    private ScanBackend $backend;

    protected function _before(): void
    {
        $this->backend = new ScanBackend();
    }

    private function seed(array $embedding): int
    {
        $asset = $this->createImageAsset();

        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'seeded',
            'embedding' => $embedding,
            'embeddingModel' => 'test-model',
        ]);
        if (!$record->save()) {
            $this->fail('Could not seed metadata: ' . json_encode($record->getErrors()));
        }

        return $asset->id;
    }

    public function testRanksExactMatchFirst(): void
    {
        $near = $this->seed([1.0, 0.0, 0.0]);
        $far = $this->seed([0.0, 1.0, 0.0]);

        $hits = $this->backend->search([1.0, 0.0, 0.0], 10, -1.0);

        $this->assertNotEmpty($hits);
        $this->assertSame($near, $hits[0]['assetId']);
        $this->assertEqualsWithDelta(1.0, $hits[0]['score'], 1e-9);

        $ids = array_column($hits, 'assetId');
        $this->assertContains($far, $ids);
        $this->assertGreaterThan(array_search($near, $ids, true), array_search($far, $ids, true));
    }

    public function testRespectsTheMinimumScore(): void
    {
        $this->seed([1.0, 0.0, 0.0]);
        $orthogonal = $this->seed([0.0, 1.0, 0.0]);

        // Orthogonal vectors score 0, so a 0.5 floor must exclude them.
        $hits = $this->backend->search([1.0, 0.0, 0.0], 10, 0.5);

        $this->assertNotContains($orthogonal, array_column($hits, 'assetId'));
    }

    public function testRespectsTheLimit(): void
    {
        $this->seed([1.0, 0.0, 0.0]);
        $this->seed([0.9, 0.1, 0.0]);
        $this->seed([0.8, 0.2, 0.0]);

        $hits = $this->backend->search([1.0, 0.0, 0.0], 2, -1.0);

        $this->assertCount(2, $hits);
    }

    public function testExcludesRequestedAssetIds(): void
    {
        $excluded = $this->seed([1.0, 0.0, 0.0]);
        $kept = $this->seed([0.9, 0.1, 0.0]);

        $hits = $this->backend->search([1.0, 0.0, 0.0], 10, -1.0, [$excluded]);

        $ids = array_column($hits, 'assetId');
        $this->assertNotContains($excluded, $ids);
        $this->assertContains($kept, $ids);
    }

    public function testSkipsRowsWhoseDimensionsDoNotMatch(): void
    {
        // Left over from a different embedding model — must be ignored rather
        // than blowing up or scoring against a truncated vector.
        $mismatched = $this->seed([1.0, 0.0]);
        $matching = $this->seed([1.0, 0.0, 0.0]);

        $hits = $this->backend->search([1.0, 0.0, 0.0], 10, -1.0);

        $ids = array_column($hits, 'assetId');
        $this->assertNotContains($mismatched, $ids);
        $this->assertContains($matching, $ids);
    }

    public function testIgnoresRowsWithoutAnEmbedding(): void
    {
        $asset = $this->createImageAsset();
        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'not analyzed yet',
        ]);
        $record->save();

        $hits = $this->backend->search([1.0, 0.0, 0.0], 10, -1.0);

        $this->assertNotContains($asset->id, array_column($hits, 'assetId'));
    }

    public function testReturnsNothingWhenThereIsNoData(): void
    {
        $this->assertSame([], $this->backend->search([1.0, 0.0, 0.0], 10, 0.5));
    }
}
