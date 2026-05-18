<?php

namespace justinholtweb\spectacles\services\embedding;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use RuntimeException;

class GeminiEmbeddingProvider implements EmbeddingProvider
{
    use Http;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function embedText(string $text): EmbeddingResult
    {
        $apiKey = $this->settings->getGeminiApiKey();
        if (!$apiKey) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $model = $this->settings->geminiEmbeddingModel;

        $response = $this->jsonRequest(
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            method: 'POST',
            path: "/models/{$model}:embedContent?key={$apiKey}",
            body: [
                'content' => [
                    'parts' => [['text' => $text]],
                ],
            ],
        );

        $vector = $response['embedding']['values'] ?? null;
        if (!is_array($vector) || !$vector) {
            throw new RuntimeException('Gemini embedding response missing vector.');
        }

        return new EmbeddingResult(
            model: $model,
            vector: array_map('floatval', $vector),
            modality: 'text',
        );
    }

    public function embedImage(string $imageData, string $mimeType): ?EmbeddingResult
    {
        return null;
    }

    public function supportsMultimodal(): bool
    {
        return false;
    }
}
