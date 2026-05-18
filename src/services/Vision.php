<?php

namespace justinholtweb\spectacles\services;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\services\embedding\EmbeddingProvider;
use justinholtweb\spectacles\services\embedding\EmbeddingResult;
use justinholtweb\spectacles\services\embedding\GeminiEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\OllamaEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\OpenAiEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\VoyageEmbeddingProvider;
use justinholtweb\spectacles\services\vision\AnalysisResult;
use justinholtweb\spectacles\services\vision\AnthropicVisionProvider;
use justinholtweb\spectacles\services\vision\GeminiVisionProvider;
use justinholtweb\spectacles\services\vision\OllamaVisionProvider;
use justinholtweb\spectacles\services\vision\OpenAiVisionProvider;
use justinholtweb\spectacles\services\vision\VisionProvider;
use RuntimeException;
use yii\base\Component;

/**
 * Resolves the configured vision and embedding providers.
 */
class Vision extends Component
{
    public function visionProvider(): VisionProvider
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->visionProvider) {
            Settings::PROVIDER_OPENAI => new OpenAiVisionProvider($settings),
            Settings::PROVIDER_ANTHROPIC => new AnthropicVisionProvider($settings),
            Settings::PROVIDER_GEMINI => new GeminiVisionProvider($settings),
            Settings::PROVIDER_OLLAMA => new OllamaVisionProvider($settings),
            default => throw new RuntimeException("Unsupported vision provider: {$settings->visionProvider}"),
        };
    }

    public function embeddingProvider(): EmbeddingProvider
    {
        $settings = Plugin::getInstance()->getSettings();

        return match ($settings->embeddingProvider) {
            Settings::PROVIDER_OPENAI => new OpenAiEmbeddingProvider($settings),
            Settings::PROVIDER_GEMINI => new GeminiEmbeddingProvider($settings),
            Settings::PROVIDER_VOYAGE => new VoyageEmbeddingProvider($settings),
            Settings::PROVIDER_OLLAMA => new OllamaEmbeddingProvider($settings),
            default => throw new RuntimeException("Unsupported embedding provider: {$settings->embeddingProvider}"),
        };
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        return $this->visionProvider()->analyze($imageData, $mimeType);
    }

    public function embedText(string $text): EmbeddingResult
    {
        return $this->embeddingProvider()->embedText($text);
    }

    public function embedImage(string $imageData, string $mimeType): ?EmbeddingResult
    {
        return $this->embeddingProvider()->embedImage($imageData, $mimeType);
    }

    /**
     * Pick the best available embedding for an image: prefer multimodal
     * (image-bytes-in) when the configured embedder supports it, else fall
     * back to embedding the analysis text.
     */
    public function embedForImage(
        string $imageData,
        string $mimeType,
        AnalysisResult $analysis,
    ): ?EmbeddingResult {
        $embedder = $this->embeddingProvider();

        if ($embedder->supportsMultimodal()) {
            $result = $embedder->embedImage($imageData, $mimeType);
            if ($result !== null) {
                return $result;
            }
        }

        $text = $analysis->embeddableText();
        if ($text === '') {
            return null;
        }

        return $embedder->embedText($text);
    }
}
