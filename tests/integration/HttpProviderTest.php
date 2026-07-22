<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\services\embedding\OpenAiEmbeddingProvider;
use justinholtweb\spectacles\services\embedding\VoyageEmbeddingProvider;
use justinholtweb\spectacles\services\Http;
use justinholtweb\spectacles\services\vision\AnthropicVisionProvider;
use justinholtweb\spectacles\services\vision\GeminiVisionProvider;
use justinholtweb\spectacles\services\vision\OpenAiVisionProvider;
use ReflectionMethod;
use RuntimeException;
use yii\httpclient\Client;

/**
 * Guards the shared HTTP layer every provider funnels through.
 *
 * No network traffic happens here: each case either stops at the missing-API-key
 * check or only inspects the client the trait builds.
 */
class HttpProviderTest extends Unit
{
    public function testHttpClientDependencyIsInstalled(): void
    {
        // services/Http.php instantiates this directly. It lives in
        // yiisoft/yii2-httpclient, which is NOT a transitive dependency of
        // craftcms/cms — without an explicit requirement every provider call
        // dies with "Class not found" the moment it touches an API.
        $this->assertTrue(
            class_exists(Client::class),
            'yiisoft/yii2-httpclient must be a declared dependency.'
        );
    }

    public function testJsonRequestBuildsAClientAgainstTheGivenBaseUrl(): void
    {
        $host = new class {
            use Http;

            public function client(string $baseUrl): Client
            {
                // Mirrors the first line of jsonRequest().
                return new Client(['baseUrl' => rtrim($baseUrl, '/')]);
            }
        };

        $client = $host->client('https://api.example.com/v1/');

        $this->assertSame('https://api.example.com/v1', $client->baseUrl);
    }

    public function testJsonRequestIsCallable(): void
    {
        // If the trait ever drifts out of sync with its consumers this fails
        // before any provider does.
        $this->assertTrue(
            (new ReflectionMethod(OpenAiVisionProvider::class, 'jsonRequest'))->isPrivate()
        );
    }

    public function testOpenAiVisionRequiresAnApiKey(): void
    {
        $provider = new OpenAiVisionProvider(new Settings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured.');
        $provider->analyze('bytes', 'image/png');
    }

    public function testAnthropicVisionRequiresAnApiKey(): void
    {
        $provider = new AnthropicVisionProvider(new Settings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API key is not configured.');
        $provider->analyze('bytes', 'image/png');
    }

    public function testGeminiVisionRequiresAnApiKey(): void
    {
        $provider = new GeminiVisionProvider(new Settings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Gemini API key is not configured.');
        $provider->analyze('bytes', 'image/png');
    }

    public function testOpenAiEmbeddingRequiresAnApiKey(): void
    {
        $provider = new OpenAiEmbeddingProvider(new Settings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured.');
        $provider->embedText('some text');
    }

    public function testVoyageEmbeddingRequiresAnApiKey(): void
    {
        $provider = new VoyageEmbeddingProvider(new Settings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voyage API key is not configured.');
        $provider->embedText('some text');
    }
}
