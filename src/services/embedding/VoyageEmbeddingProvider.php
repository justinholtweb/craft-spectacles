<?php

namespace justinholtweb\spectacles\services\embedding;

use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use RuntimeException;

/**
 * Voyage AI's multimodal embeddings — pairs nicely with any vision provider
 * because it can embed the raw image directly, capturing visual features the
 * description text would lose.
 */
class VoyageEmbeddingProvider implements EmbeddingProvider
{
    use Http;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function embedText(string $text): EmbeddingResult
    {
        return $this->embedMultimodal([['type' => 'text', 'text' => $text]], modality: 'text');
    }

    public function embedImage(string $imageData, string $mimeType): ?EmbeddingResult
    {
        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        return $this->embedMultimodal(
            [['type' => 'image_base64', 'image_base64' => $dataUrl]],
            modality: 'image',
        );
    }

    public function supportsMultimodal(): bool
    {
        return true;
    }

    private function embedMultimodal(array $contentParts, string $modality): EmbeddingResult
    {
        $apiKey = $this->settings->getVoyageApiKey();
        if (!$apiKey) {
            throw new RuntimeException('Voyage API key is not configured.');
        }

        $response = $this->jsonRequest(
            baseUrl: 'https://api.voyageai.com/v1',
            method: 'POST',
            path: '/multimodalembeddings',
            body: [
                'inputs' => [['content' => $contentParts]],
                'model' => $this->settings->voyageEmbeddingModel,
                'input_type' => 'document',
            ],
            headers: ['Authorization' => 'Bearer ' . $apiKey],
        );

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector) || !$vector) {
            throw new RuntimeException('Voyage embedding response missing vector.');
        }

        return new EmbeddingResult(
            model: $this->settings->voyageEmbeddingModel,
            vector: array_map('floatval', $vector),
            modality: $modality,
        );
    }
}
