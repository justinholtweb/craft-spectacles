<?php

namespace justinholtweb\spectacles\services\vision;

use craft\helpers\Json;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use justinholtweb\spectacles\services\ProviderHelpers;
use RuntimeException;

class OpenAiVisionProvider implements VisionProvider
{
    use Http;
    use ProviderHelpers;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        $apiKey = $this->settings->getOpenAiApiKey();
        if (!$apiKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

        $response = $this->jsonRequest(
            baseUrl: 'https://api.openai.com/v1',
            method: 'POST',
            path: '/chat/completions',
            body: [
                'model' => $this->settings->openaiVisionModel,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Describe this image and extract structured metadata for similarity search.'],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ],
                ],
            ],
            headers: ['Authorization' => 'Bearer ' . $apiKey],
        );

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('OpenAI vision response missing content.');
        }

        $parsed = Json::decodeIfJson($content);
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenAI vision response was not valid JSON.');
        }

        return $this->buildAnalysisResult(
            $parsed,
            provider: Settings::PROVIDER_OPENAI,
            model: $this->settings->openaiVisionModel,
        );
    }
}
