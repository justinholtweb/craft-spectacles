<?php

namespace justinholtweb\spectacles\models;

use craft\base\Model;

class Settings extends Model
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';

    public string $visionProvider = self::PROVIDER_OPENAI;
    public ?string $openaiApiKey = null;
    public string $openaiVisionModel = 'gpt-4o-mini';
    public string $openaiEmbeddingModel = 'text-embedding-3-small';
    public ?string $anthropicApiKey = null;
    public string $anthropicVisionModel = 'claude-sonnet-4-6';

    public bool $autoAnalyzeOnUpload = true;
    public array $volumeUids = [];
    public int $defaultResultLimit = 12;
    public float $minSimilarityScore = 0.5;
    public int $maxVisitorUploadKb = 8192;
    public bool $allowPublicSearch = true;

    public function rules(): array
    {
        return [
            [['visionProvider'], 'in', 'range' => [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC]],
            [['openaiVisionModel', 'openaiEmbeddingModel', 'anthropicVisionModel'], 'string'],
            [['openaiApiKey', 'anthropicApiKey'], 'string'],
            [['autoAnalyzeOnUpload', 'allowPublicSearch'], 'boolean'],
            [['defaultResultLimit', 'maxVisitorUploadKb'], 'integer', 'min' => 1],
            [['minSimilarityScore'], 'number', 'min' => 0, 'max' => 1],
            [['volumeUids'], 'each', 'rule' => ['string']],
        ];
    }

    public function getOpenAiApiKey(): ?string
    {
        return $this->parseEnv($this->openaiApiKey);
    }

    public function getAnthropicApiKey(): ?string
    {
        return $this->parseEnv($this->anthropicApiKey);
    }

    private function parseEnv(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return \Craft::parseEnv($value);
    }
}
