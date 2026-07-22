<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\embedding\GeminiEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\OllamaEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\OpenAiEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\VoyageEmbeddingProvider;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use justinholtweb\spectacles\services\vision\AnthropicVisionProvider;
use justinholtweb\spectacles\services\vision\GeminiVisionProvider;
use justinholtweb\spectacles\services\vision\OllamaVisionProvider;
use justinholtweb\spectacles\services\vision\OpenAiVisionProvider;
use justinholtweb\spectacles\services\Vision;
use RuntimeException;

/**
 * Provider resolution. No network calls happen here — we only assert that the
 * configured provider key maps to the right class and that unknown keys fail
 * loudly rather than silently doing nothing.
 */
class VisionServiceTest extends Unit
{
    private Vision $vision;

    protected function _before(): void
    {
        $this->vision = new Vision();
    }

    private function withSettings(array $settings): void
    {
        Plugin::getInstance()->setSettings($settings);
    }

    /**
     * @dataProvider visionProviderProvider
     */
    public function testResolvesVisionProviders(string $key, string $expected): void
    {
        $this->withSettings(['visionProvider' => $key]);

        $this->assertInstanceOf($expected, $this->vision->visionProvider());
    }

    public static function visionProviderProvider(): array
    {
        return [
            'openai' => [Settings::PROVIDER_OPENAI, OpenAiVisionProvider::class],
            'anthropic' => [Settings::PROVIDER_ANTHROPIC, AnthropicVisionProvider::class],
            'gemini' => [Settings::PROVIDER_GEMINI, GeminiVisionProvider::class],
            'ollama' => [Settings::PROVIDER_OLLAMA, OllamaVisionProvider::class],
        ];
    }

    /**
     * @dataProvider embeddingProviderProvider
     */
    public function testResolvesEmbeddingProviders(string $key, string $expected): void
    {
        $this->withSettings(['embeddingProvider' => $key]);

        $this->assertInstanceOf($expected, $this->vision->embeddingProvider());
    }

    public static function embeddingProviderProvider(): array
    {
        return [
            'openai' => [Settings::PROVIDER_OPENAI, OpenAiEmbeddingProvider::class],
            'gemini' => [Settings::PROVIDER_GEMINI, GeminiEmbeddingProvider::class],
            'voyage' => [Settings::PROVIDER_VOYAGE, VoyageEmbeddingProvider::class],
            'ollama' => [Settings::PROVIDER_OLLAMA, OllamaEmbeddingProvider::class],
        ];
    }

    public function testUnsupportedVisionProviderThrows(): void
    {
        // Voyage is embedding-only.
        $this->withSettings(['visionProvider' => Settings::PROVIDER_VOYAGE]);

        $this->expectException(RuntimeException::class);
        $this->vision->visionProvider();
    }

    public function testUnsupportedEmbeddingProviderThrows(): void
    {
        // Anthropic is vision-only.
        $this->withSettings(['embeddingProvider' => Settings::PROVIDER_ANTHROPIC]);

        $this->expectException(RuntimeException::class);
        $this->vision->embeddingProvider();
    }

    public function testOnlyVoyageAdvertisesMultimodalSupport(): void
    {
        $settings = new Settings();

        $this->assertTrue((new VoyageEmbeddingProvider($settings))->supportsMultimodal());
        $this->assertFalse((new OpenAiEmbeddingProvider($settings))->supportsMultimodal());
        $this->assertFalse((new GeminiEmbeddingProvider($settings))->supportsMultimodal());
        $this->assertFalse((new OllamaEmbeddingProvider($settings))->supportsMultimodal());
    }

    public function testNonMultimodalProvidersReturnNullForImageEmbedding(): void
    {
        $settings = new Settings();

        $this->assertNull((new OpenAiEmbeddingProvider($settings))->embedImage('bytes', 'image/png'));
        $this->assertNull((new GeminiEmbeddingProvider($settings))->embedImage('bytes', 'image/png'));
        $this->assertNull((new OllamaEmbeddingProvider($settings))->embedImage('bytes', 'image/png'));
    }

    public function testEmbedForImageReturnsNullWhenThereIsNothingToEmbed(): void
    {
        // A non-multimodal embedder with an empty analysis has no text to send,
        // so it must bail out instead of calling the API with an empty string.
        $this->withSettings(['embeddingProvider' => Settings::PROVIDER_OPENAI]);

        $empty = new AnalysisResult(description: '');

        $this->assertNull($this->vision->embedForImage('bytes', 'image/png', $empty));
    }
}
