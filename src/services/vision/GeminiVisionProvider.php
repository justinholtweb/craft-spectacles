<?php

namespace justinholtweb\spectacles\services\vision;

use craft\helpers\Json;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use justinholtweb\spectacles\services\ProviderHelpers;
use RuntimeException;

class GeminiVisionProvider implements VisionProvider
{
    use Http;
    use ProviderHelpers;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        $apiKey = $this->settings->getGeminiApiKey();
        if (!$apiKey) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $model = $this->settings->geminiVisionModel;

        $response = $this->jsonRequest(
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            method: 'POST',
            path: "/models/{$model}:generateContent?key={$apiKey}",
            body: [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt()]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => 'Describe this image and extract structured metadata for similarity search.'],
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => base64_encode($imageData),
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ],
        );

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            throw new RuntimeException('Gemini response missing text content.');
        }

        $parsed = Json::decodeIfJson($text);
        if (!is_array($parsed)) {
            throw new RuntimeException('Gemini response was not valid JSON.');
        }

        return $this->buildAnalysisResult(
            $parsed,
            provider: Settings::PROVIDER_GEMINI,
            model: $model,
        );
    }
}
