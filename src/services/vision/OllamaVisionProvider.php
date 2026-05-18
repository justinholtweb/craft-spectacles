<?php

namespace justinholtweb\spectacles\services\vision;

use craft\helpers\Json;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use justinholtweb\spectacles\services\ProviderHelpers;
use RuntimeException;

/**
 * Local vision via Ollama. Set ollamaBaseUrl to your daemon (default
 * http://localhost:11434) and use models like `llava`, `llama3.2-vision`, or
 * `bakllava`.
 */
class OllamaVisionProvider implements VisionProvider
{
    use Http;
    use ProviderHelpers;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        $response = $this->jsonRequest(
            baseUrl: $this->settings->getOllamaBaseUrl(),
            method: 'POST',
            path: '/api/generate',
            body: [
                'model' => $this->settings->ollamaVisionModel,
                'prompt' => $this->systemPrompt() . "\n\nDescribe this image and extract structured metadata for similarity search. JSON only.",
                'images' => [base64_encode($imageData)],
                'format' => 'json',
                'stream' => false,
            ],
            timeout: 180,
        );

        $text = $response['response'] ?? null;
        if (!is_string($text)) {
            throw new RuntimeException('Ollama vision response missing content.');
        }

        $parsed = Json::decodeIfJson($text);
        if (!is_array($parsed)) {
            throw new RuntimeException('Ollama vision response was not valid JSON.');
        }

        return $this->buildAnalysisResult(
            $parsed,
            provider: Settings::PROVIDER_OLLAMA,
            model: $this->settings->ollamaVisionModel,
        );
    }
}
