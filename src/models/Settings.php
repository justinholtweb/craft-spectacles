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
    /**
     * Whether the anonymous `/spectacles/search` and `/spectacles/similar/<id>` endpoints answer.
     * Off by default since 5.1.0: each call spends on a paid API and reads the index.
     */
    public bool $allowPublicSearch = false;

    /**
     * The volumes whose images the public endpoints may return — and may be asked about. Empty
     * means none. Kept apart from `volumeUids` (what gets analysed) because analysing an internal
     * volume for the control panel is not the same as publishing it: before 5.1.0 the public
     * endpoints returned titles, filenames, URLs and AI descriptions from every indexed volume.
     *
     * @var string[]
     */
    public array $publicVolumeUids = [];
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
            [['volumeUids', 'publicVolumeUids'], 'each', 'rule' => ['string']],
        ];
    }

    /** @return int[] the public volumes' ids, for the public endpoints. */
    public function getPublicVolumeIds(): array
    {
        return array_values(array_filter(array_map(
            fn(string $uid): ?int => \Craft::$app->getVolumes()->getVolumeByUid($uid)?->id,
            $this->publicVolumeUids,
        )));
    }

    /**
     * The model the configured embedding provider writes vectors with — what an analysed image's
     * `embeddingModel` must equal for its vector to be comparable with new ones. A record with any
     * other model was made before a provider or model switch and needs re-indexing.
     */
    public function getEmbeddingModel(): string
    {
        return match ($this->embeddingProvider) {
            self::PROVIDER_GEMINI => $this->geminiEmbeddingModel,
            self::PROVIDER_VOYAGE => $this->voyageEmbeddingModel,
            self::PROVIDER_OLLAMA => $this->ollamaEmbeddingModel,
            default => $this->openaiEmbeddingModel,
        };
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
