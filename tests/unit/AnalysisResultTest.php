<?php

namespace justinholtweb\spectacles\tests\unit;

use justinholtweb\spectacles\services\vision\AnalysisResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests the text assembled for embedding from a vision analysis.
 */
class AnalysisResultTest extends TestCase
{
    public function testEmbeddableTextCombinesAllSections(): void
    {
        $result = new AnalysisResult(
            description: 'A foggy mountain ridge at sunrise.',
            tags: ['mountain', 'fog'],
            objects: ['mountain', 'trees'],
            colors: ['pink', 'blue'],
        );

        $this->assertSame(
            "A foggy mountain ridge at sunrise.\n"
            . "Tags: mountain, fog\n"
            . "Objects: mountain, trees\n"
            . 'Colors: pink, blue',
            $result->embeddableText()
        );
    }

    public function testEmbeddableTextWithDescriptionOnly(): void
    {
        $result = new AnalysisResult(description: 'Just a description.');

        $this->assertSame('Just a description.', $result->embeddableText());
    }

    public function testEmbeddableTextDropsEmptyDescription(): void
    {
        $result = new AnalysisResult(description: '', tags: ['solo']);

        $this->assertSame('Tags: solo', $result->embeddableText());
    }

    public function testDefaultsAreEmpty(): void
    {
        $result = new AnalysisResult(description: 'x');

        $this->assertSame([], $result->tags);
        $this->assertSame([], $result->objects);
        $this->assertSame([], $result->colors);
        $this->assertSame([], $result->raw);
        $this->assertSame('', $result->provider);
        $this->assertSame('', $result->model);
    }
}
