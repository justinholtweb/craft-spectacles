<?php

namespace justinholtweb\spectacles\services\vision;

use Craft;
use craft\helpers\Json;
use justinholtweb\spectacles\models\Settings;
use RuntimeException;
use yii\httpclient\Client;

class OpenAiProvider implements VisionProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1';

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

        $body = [
            'model' => $this->settings->openaiVisionModel,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You analyze images for similarity search. Respond with strict JSON matching the schema: {"description": string, "tags": string[], "objects": string[], "colors": string[]}. Tags should be lowercase, single concepts. Colors should be plain English names. No prose, JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image and extract structured metadata for similarity search.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', '/chat/completions', $body, $apiKey);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('OpenAI vision response missing content.');
        }

        $parsed = Json::decodeIfJson($content);
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenAI vision response was not valid JSON.');
        }

        return new AnalysisResult(
            description: (string)($parsed['description'] ?? ''),
            tags: $this->normalizeStringList($parsed['tags'] ?? []),
            objects: $this->normalizeStringList($parsed['objects'] ?? []),
            colors: $this->normalizeStringList($parsed['colors'] ?? []),
            raw: $parsed,
            provider: Settings::PROVIDER_OPENAI,
            model: $this->settings->openaiVisionModel,
        );
    }

    public function embedText(string $text): array
    {
        $apiKey = $this->settings->getOpenAiApiKey();
        if (!$apiKey) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $response = $this->request('POST', '/embeddings', [
            'model' => $this->settings->openaiEmbeddingModel,
            'input' => $text,
        ], $apiKey);

        $vector = $response['data'][0]['embedding'] ?? null;
        if (!is_array($vector) || !$vector) {
            throw new RuntimeException('OpenAI embedding response missing vector.');
        }

        return [
            'model' => $this->settings->openaiEmbeddingModel,
            'vector' => array_map('floatval', $vector),
        ];
    }

    private function request(string $method, string $path, array $body, string $apiKey): array
    {
        $client = new Client(['baseUrl' => self::ENDPOINT]);
        $request = $client->createRequest()
            ->setMethod($method)
            ->setUrl(ltrim($path, '/'))
            ->setHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->setContent(Json::encode($body))
            ->setOptions(['timeout' => 60]);

        $response = $client->send($request);

        if (!$response->isOk) {
            $errorBody = is_array($response->data) ? Json::encode($response->data) : (string)$response->content;
            Craft::error('Spectacles OpenAI request failed: ' . $errorBody, __METHOD__);
            throw new RuntimeException('OpenAI request failed: HTTP ' . $response->statusCode);
        }

        if (!is_array($response->data)) {
            throw new RuntimeException('OpenAI response was not JSON.');
        }

        return $response->data;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = strtolower(trim($item));
            }
        }
        return array_values(array_unique($out));
    }
}
