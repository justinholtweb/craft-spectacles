<?php

namespace justinholtweb\spectacles\tests\unit;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\vision\AnthropicVisionProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Claude does not honour a strict JSON mode the way OpenAI does — it wraps its
 * answer in markdown fences or prose often enough that the provider has to dig
 * the object out. extractJson() is private and makes no network calls, so we
 * reach it directly.
 */
class AnthropicJsonExtractionTest extends TestCase
{
    private ReflectionMethod $extractJson;
    private AnthropicVisionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new AnthropicVisionProvider(new Settings());
        $this->extractJson = new ReflectionMethod(AnthropicVisionProvider::class, 'extractJson');
        $this->extractJson->setAccessible(true);
    }

    private function extract(string $text): mixed
    {
        return $this->extractJson->invoke($this->provider, $text);
    }

    public function testParsesBareJson(): void
    {
        $this->assertSame(
            ['description' => 'a cat'],
            $this->extract('{"description":"a cat"}')
        );
    }

    public function testStripsJsonFences(): void
    {
        $text = "```json\n{\"description\":\"a cat\"}\n```";

        $this->assertSame(['description' => 'a cat'], $this->extract($text));
    }

    public function testStripsPlainFences(): void
    {
        $text = "```\n{\"description\":\"a cat\"}\n```";

        $this->assertSame(['description' => 'a cat'], $this->extract($text));
    }

    public function testDigsJsonOutOfSurroundingProse(): void
    {
        $text = 'Sure! Here is the metadata you asked for: {"description":"a cat"} Let me know if you need more.';

        $this->assertSame(['description' => 'a cat'], $this->extract($text));
    }

    public function testHandlesNestedObjects(): void
    {
        $text = 'Here you go: {"description":"a cat","meta":{"tags":["a","b"]}}';

        $this->assertSame(
            ['description' => 'a cat', 'meta' => ['tags' => ['a', 'b']]],
            $this->extract($text)
        );
    }

    public function testReturnsNonArrayWhenThereIsNoJson(): void
    {
        // The provider treats any non-array result as a hard failure, so the
        // only contract here is "does not return an array".
        $this->assertIsNotArray($this->extract('I cannot help with that.'));
    }
}
