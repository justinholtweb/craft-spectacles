<?php

namespace justinholtweb\spectacles\services\embedding;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use RuntimeException;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    use Http;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function embedText(string $text): EmbeddingResult
    {
        $apiKey = $this->settings->getOpenAiApiKey();
        if (!$apiKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $response = $this->jsonRequest(
            baseUrl: 'https://api.openai.com/v1',
            method: 'POST',
            path: '/embeddings',
            body: [
                'model' => $this->settings->openaiEmbeddingModel,
                'input' => $text,
            ],
            headers: ['Authorization' => 'Bearer ' . $apiKey],
        );

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector) || !$vector) {
            throw new RuntimeException('OpenAI embedding response missing vector.');
        }

        return new EmbeddingResult(
            model: $this->settings->openaiEmbeddingModel,
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
