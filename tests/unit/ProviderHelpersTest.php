<?php

namespace justinholtweb\spectacles\tests\unit;

use justinholtweb\spectacles\services\ProviderHelpers;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests the provider-agnostic normalization shared by every vision provider.
 */
class ProviderHelpersTest extends TestCase
{
    /** @var object Anonymous host exposing the trait's private helpers. */
    private object $host;

    protected function setUp(): void
    {
        $this->host = new class {
            use ProviderHelpers;

            public function norm(mixed $value): array
            {
                return $this->normalizeStringList($value);
            }

            public function build(array $parsed, string $provider, string $model): AnalysisResult
            {
                return $this->buildAnalysisResult($parsed, $provider, $model);
            }
        };
    }

    public function testNormalizeLowercasesAndTrims(): void
    {
        $this->assertSame(['mountain', 'fog'], $this->host->norm(['  Mountain ', 'FOG']));
    }

    public function testNormalizeDeduplicates(): void
    {
        $this->assertSame(['blue'], $this->host->norm(['blue', 'Blue', ' BLUE ']));
    }

    public function testNormalizeDropsEmptyAndNonStringEntries(): void
    {
        $this->assertSame(['ok'], $this->host->norm(['ok', '', 42, null, ['x'], true]));
    }

    public function testNormalizeReturnsEmptyArrayForNonArray(): void
    {
        $this->assertSame([], $this->host->norm('not-an-array'));
        $this->assertSame([], $this->host->norm(null));
    }

    public function testBuildAnalysisResultMapsFields(): void
    {
        $result = $this->host->build([
            'description' => 'A red car',
            'tags' => ['Car', 'red', 'car'],
            'objects' => ['Vehicle'],
            'colors' => ['Red'],
        ], 'openai', 'gpt-4o-mini');

        $this->assertSame('A red car', $result->description);
        $this->assertSame(['car', 'red'], $result->tags);
        $this->assertSame(['vehicle'], $result->objects);
        $this->assertSame(['red'], $result->colors);
        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-4o-mini', $result->model);
    }

    public function testBuildAnalysisResultToleratesMissingKeys(): void
    {
        $result = $this->host->build([], 'gemini', 'gemini-2.5-flash');

        $this->assertSame('', $result->description);
        $this->assertSame([], $result->tags);
        $this->assertSame([], $result->objects);
        $this->assertSame([], $result->colors);
        $this->assertSame([], $result->raw);
    }
}
