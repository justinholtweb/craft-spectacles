<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\models\Settings;

/**
 * Validation and env-parsing for the plugin settings model.
 *
 * These live in the integration suite rather than the plain PHPUnit one
 * because Yii's validators resolve through Yii::createObject(), which needs a
 * booted application.
 */
class SettingsTest extends Unit
{
    public function testDefaultsAreValid(): void
    {
        $this->assertTrue((new Settings())->validate());
    }

    public function testRejectsUnknownVisionProvider(): void
    {
        $settings = new Settings();
        $settings->visionProvider = 'nope';
        $this->assertFalse($settings->validate(['visionProvider']));
    }

    public function testVoyageIsNotAValidVisionProvider(): void
    {
        // Voyage is embedding-only; it must not be selectable for vision.
        $settings = new Settings();
        $settings->visionProvider = Settings::PROVIDER_VOYAGE;
        $this->assertFalse($settings->validate(['visionProvider']));
    }

    public function testAnthropicIsNotAValidEmbeddingProvider(): void
    {
        // Anthropic is vision-only; it must not be selectable for embeddings.
        $settings = new Settings();
        $settings->embeddingProvider = Settings::PROVIDER_ANTHROPIC;
        $this->assertFalse($settings->validate(['embeddingProvider']));
    }

    public function testMinSimilarityScoreMustBeBetweenZeroAndOne(): void
    {
        $settings = new Settings();
        $settings->minSimilarityScore = 1.5;
        $this->assertFalse($settings->validate(['minSimilarityScore']));

        $settings = new Settings();
        $settings->minSimilarityScore = 0.75;
        $this->assertTrue($settings->validate(['minSimilarityScore']));
    }

    public function testRateLimitMayBeZeroButResultLimitMayNot(): void
    {
        // A rate limit of 0 disables throttling, so it is allowed.
        $settings = new Settings();
        $settings->publicSearchRateLimit = 0;
        $this->assertTrue($settings->validate(['publicSearchRateLimit']));

        // A result limit of 0 would return nothing, so it is rejected.
        $settings = new Settings();
        $settings->defaultResultLimit = 0;
        $this->assertFalse($settings->validate(['defaultResultLimit']));
    }

    public function testRejectsUnknownVectorIndex(): void
    {
        $settings = new Settings();
        $settings->vectorIndex = 'redis';
        $this->assertFalse($settings->validate(['vectorIndex']));
    }

    public function testApiKeyGettersReturnNullWhenUnset(): void
    {
        $settings = new Settings();

        $this->assertNull($settings->getOpenAiApiKey());
        $this->assertNull($settings->getAnthropicApiKey());
        $this->assertNull($settings->getGeminiApiKey());
        $this->assertNull($settings->getVoyageApiKey());
    }

    public function testApiKeyGettersPassThroughLiteralValues(): void
    {
        $settings = new Settings();
        $settings->openaiApiKey = 'sk-literal-key';

        $this->assertSame('sk-literal-key', $settings->getOpenAiApiKey());
    }

    public function testApiKeyGettersResolveEnvironmentVariables(): void
    {
        putenv('SPECTACLES_TEST_KEY=sk-from-env');
        $_SERVER['SPECTACLES_TEST_KEY'] = 'sk-from-env';

        try {
            $settings = new Settings();
            $settings->openaiApiKey = '$SPECTACLES_TEST_KEY';

            $this->assertSame('sk-from-env', $settings->getOpenAiApiKey());
        } finally {
            putenv('SPECTACLES_TEST_KEY');
            unset($_SERVER['SPECTACLES_TEST_KEY']);
        }
    }

    public function testUnresolvableEnvVariableYieldsNull(): void
    {
        $settings = new Settings();
        $settings->openaiApiKey = '$SPECTACLES_DEFINITELY_NOT_SET';

        $this->assertNull($settings->getOpenAiApiKey());
    }

    public function testOllamaBaseUrlFallsBackToTheLocalDaemon(): void
    {
        $settings = new Settings();
        $settings->ollamaBaseUrl = '';

        $this->assertSame('http://localhost:11434', $settings->getOllamaBaseUrl());
    }

    public function testBooleanLikeEnvValueDoesNotYieldAUsableApiKey(): void
    {
        // craft\helpers\App::parseEnv() coerces values like "false"/"off" into
        // a real bool, which the `?string` return type then weak-coerces to "".
        // The important part is that it stays falsy, so providers report "not
        // configured" instead of sending a garbage Authorization header.
        putenv('SPECTACLES_TEST_BOOLISH=false');
        $_SERVER['SPECTACLES_TEST_BOOLISH'] = 'false';

        try {
            $settings = new Settings();
            $settings->openaiApiKey = '$SPECTACLES_TEST_BOOLISH';

            $this->assertEmpty($settings->getOpenAiApiKey());
        } finally {
            putenv('SPECTACLES_TEST_BOOLISH');
            unset($_SERVER['SPECTACLES_TEST_BOOLISH']);
        }
    }

    public function testEmbeddingModelFollowsTheEmbeddingProvider(): void
    {
        $settings = new Settings([
            'openaiEmbeddingModel' => 'o',
            'geminiEmbeddingModel' => 'g',
            'voyageEmbeddingModel' => 'v',
            'ollamaEmbeddingModel' => 'l',
        ]);

        foreach ([Settings::PROVIDER_OPENAI => 'o', Settings::PROVIDER_GEMINI => 'g', Settings::PROVIDER_VOYAGE => 'v', Settings::PROVIDER_OLLAMA => 'l'] as $provider => $model) {
            $settings->embeddingProvider = $provider;
            $this->assertSame($model, $settings->getEmbeddingModel(), $provider);
        }
    }
}
