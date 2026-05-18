<?php

namespace justinholtweb\spectacles\services\vision;

use craft\helpers\Json;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\Http;
use justinholtweb\spectacles\services\ProviderHelpers;
use RuntimeException;

class AnthropicVisionProvider implements VisionProvider
{
    use Http;
    use ProviderHelpers;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function analyze(string $imageData, string $mimeType): AnalysisResult
    {
        $apiKey = $this->settings->getAnthropicApiKey();
        if (!$apiKey) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $response = $this->jsonRequest(
            baseUrl: 'https://api.anthropic.com/v1',
            method: 'POST',
            path: '/messages',
            body: [
                'model' => $this->settings->anthropicVisionModel,
                'max_tokens' => 1024,
                'system' => $this->systemPrompt(),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => $mimeType,
                                    'data' => base64_encode($imageData),
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Describe this image and extract structured metadata for similarity search. JSON only, no prose.',
                            ],
                        ],
                    ],
                ],
            ],
            headers: [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
        );

        $text = $response['content'][0]['text'] ?? null;
        if (!is_string($text)) {
            throw new RuntimeException('Anthropic response missing text content.');
        }

        $parsed = $this->extractJson($text);
        if (!is_array($parsed)) {
            throw new RuntimeException('Anthropic response was not valid JSON.');
        }

        return $this->buildAnalysisResult(
            $parsed,
            provider: Settings::PROVIDER_ANTHROPIC,
            model: $this->settings->anthropicVisionModel,
        );
    }

    /**
     * Claude won't honour json-mode the way OpenAI does — it sometimes wraps
     * its JSON in prose or markdown fences. Strip the fences and parse.
     */
    private function extractJson(string $text): mixed
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }
        return Json::decodeIfJson($text);
    }
}
