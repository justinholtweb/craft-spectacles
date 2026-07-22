<?php

namespace justinholtweb\spectacles\tests\unit;

use justinholtweb\spectacles\services\similarity\ScanBackend;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises the cosine-similarity math at the heart of the scan backend.
 *
 * cosine() is private and has no DB dependency, so we reach it via reflection
 * rather than going through search() (which queries ImageMetadata).
 */
class ScanBackendTest extends TestCase
{
    private ScanBackend $backend;
    private ReflectionMethod $cosine;

    protected function setUp(): void
    {
        $this->backend = new ScanBackend();
        $this->cosine = new ReflectionMethod(ScanBackend::class, 'cosine');
        $this->cosine->setAccessible(true);
    }

    private function cosine(array $a, array $b): float
    {
        return $this->cosine->invoke($this->backend, $a, $b);
    }

    public function testIdenticalVectorsScoreOne(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]), 1e-9);
    }

    public function testSameDirectionDifferentMagnitudeScoresOne(): void
    {
        // Cosine is scale-invariant: [2,4,6] is [1,2,3] scaled by 2.
        $this->assertEqualsWithDelta(1.0, $this->cosine([1.0, 2.0, 3.0], [2.0, 4.0, 6.0]), 1e-9);
    }

    public function testOrthogonalVectorsScoreZero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->cosine([1.0, 0.0], [0.0, 1.0]), 1e-9);
    }

    public function testOppositeVectorsScoreNegativeOne(): void
    {
        $this->assertEqualsWithDelta(-1.0, $this->cosine([1.0, 2.0], [-1.0, -2.0]), 1e-9);
    }

    public function testZeroVectorScoresZero(): void
    {
        $this->assertSame(0.0, $this->cosine([0.0, 0.0, 0.0], [1.0, 2.0, 3.0]));
    }

    public function testEmptyVectorsScoreZero(): void
    {
        $this->assertSame(0.0, $this->cosine([], []));
    }
}
