<?php

namespace justinholtweb\spectacles\models;

use craft\base\Model;

class Settings extends Model
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_VOYAGE = 'voyage';
    public const PROVIDER_OLLAMA = 'ollama';

    public const VISION_PROVIDERS = [
        self::PROVIDER_OPENAI,
        self::PROVIDER_ANTHROPIC,
        self::PROVIDER_GEMINI,
        self::PROVIDER_OLLAMA,
    ];

    public const EMBEDDING_PROVIDERS = [
        self::PROVIDER_OPENAI,
        self::PROVIDER_GEMINI,
        self::PROVIDER_VOYAGE,
        self::PROVIDER_OLLAMA,
    ];

    public const INDEX_AUTO = 'auto';
    public const INDEX_SCAN = 'scan';
    public const INDEX_PGVECTOR = 'pgvector';

    public string $vectorIndex = self::INDEX_AUTO;

    public string $visionProvider = self::PROVIDER_OPENAI;
    public string $embeddingProvider = self::PROVIDER_OPENAI;

    // OpenAI
    public ?string $openaiApiKey = null;
    public string $openaiVisionModel = 'gpt-4o-mini';
    public string $openaiEmbeddingModel = 'text-embedding-3-small';

    // Anthropic (vision only)
    public ?string $anthropicApiKey = null;
    public string $anthropicVisionModel = 'claude-sonnet-4-6';

    // Gemini
    public ?string $geminiApiKey = null;
    public string $geminiVisionModel = 'gemini-2.5-flash';
    public string $geminiEmbeddingModel = 'text-embedding-004';

    // Voyage (embedding only, multimodal)
    public ?string $voyageApiKey = null;
    public string $voyageEmbeddingModel = 'voyage-multimodal-3';

    // Ollama (local)
    public string $ollamaBaseUrl = 'http://localhost:11434';
    public string $ollamaVisionModel = 'llava';
    public string $ollamaEmbeddingModel = 'nomic-embed-text';

    // General
    public bool $autoAnalyzeOnUpload = true;
    public array $volumeUids = [];
    public int $defaultResultLimit = 12;
    public float $minSimilarityScore = 0.5;
    public int $maxVisitorUploadKb = 8192;
    public bool $allowPublicSearch = true;
    public int $publicSearchRateLimit = 10;
    public int $publicSearchRateWindow = 60;

    public function rules(): array
    {
        return [
            [['visionProvider'], 'in', 'range' => self::VISION_PROVIDERS],
            [['embeddingProvider'], 'in', 'range' => self::EMBEDDING_PROVIDERS],
            [['vectorIndex'], 'in', 'range' => [self::INDEX_AUTO, self::INDEX_SCAN, self::INDEX_PGVECTOR]],
            [[
                'openaiVisionModel', 'openaiEmbeddingModel',
                'anthropicVisionModel',
                'geminiVisionModel', 'geminiEmbeddingModel',
                'voyageEmbeddingModel',
                'ollamaBaseUrl', 'ollamaVisionModel', 'ollamaEmbeddingModel',
            ], 'string'],
            [[
                'openaiApiKey', 'anthropicApiKey', 'geminiApiKey', 'voyageApiKey',
            ], 'string'],
            [['autoAnalyzeOnUpload', 'allowPublicSearch'], 'boolean'],
            [['defaultResultLimit', 'maxVisitorUploadKb', 'publicSearchRateWindow'], 'integer', 'min' => 1],
            [['publicSearchRateLimit'], 'integer', 'min' => 0],
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

    public function getGeminiApiKey(): ?string
    {
        return $this->parseEnv($this->geminiApiKey);
    }

    public function getVoyageApiKey(): ?string
    {
        return $this->parseEnv($this->voyageApiKey);
    }

    public function getOllamaBaseUrl(): string
    {
        return $this->parseEnv($this->ollamaBaseUrl) ?? 'http://localhost:11434';
    }

    private function parseEnv(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return \Craft::parseEnv($value);
    }
}
