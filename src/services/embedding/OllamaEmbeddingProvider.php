<?php

namespace justinholtweb\spectacles\services\embedding;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use RuntimeException;

class OllamaEmbeddingProvider implements EmbeddingProvider
{
    use Http;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function embedText(string $text): EmbeddingResult
    {
        $response = $this->jsonRequest(
            baseUrl: $this->settings->getOllamaBaseUrl(),
            method: 'POST',
            path: '/api/embeddings',
            body: [
                'model' => $this->settings->ollamaEmbeddingModel,
                'prompt' => $text,
            ],
            timeout: 60,
        );

        $vector = $response['embedding'] ?? null;
        if (!is_array($vector) || !$vector) {
            throw new RuntimeException('Ollama embedding response missing vector.');
        }

        return new EmbeddingResult(
            model: $this->settings->ollamaEmbeddingModel,
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
