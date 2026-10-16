<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAiCompatible;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class EmbeddingsTest extends TestCase
{
    /**
     * Configure the OpenAI-compatible provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai-compatible', [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => [
                'text' => ['default' => 'local-model'],
                'embeddings' => ['default' => 'local-embeddings-model', 'dimensions' => 768],
            ],
        ]);
    }

    public function testEmbeddingsRequestIsCorrectlyFormatted(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 5],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello world']);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'local-embeddings-model'
                && $body['input'] === ['Hello world']
                && $body['dimensions'] === 768
                && $request->url() === 'http://localhost:1234/v1/embeddings';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['prompt_tokens' => 10],
        ])]);

        $response = Ai::instance('openai-compatible')->embeddings(['Hello', 'World']);

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('openai-compatible', $response->meta->provider);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['test']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsRequestOmitsAuthorizationHeaderWhenNoKeyIsConfigured(): void
    {
        config(['ai.providers.openai-compatible.key' => null]);

        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['test']);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(
            inputs: ['Hello'],
            providerOptions: ['user' => 'test-user'],
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['user'] === 'test-user'
                && $body['input'] === ['Hello'];
        });
    }

    public function testEmbeddingsRejectResponseFormatsThatDoNotReturnFloatVectors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports float embedding responses');
        Ai::instance('openai-compatible')->embeddings(
            inputs: ['Hello'],
            providerOptions: ['encoding_format' => 'base64'],
        );
    }

    public function testThrowsWhenNoDefaultEmbeddingsModelIsConfiguredAndNoneIsPassed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a default embeddings model');
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
        ]]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }

    public function testEmbeddingsRequestOmitsDimensionsWhenNoneAreConfigured(): void
    {
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
        ]]);

        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'local-embeddings-model'
                && ! array_key_exists('dimensions', $body);
        });
    }

    public function testEmbeddingsRequestOmitsDimensionsWhenAnExplicitModelIsPassed(): void
    {
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
        ]]);

        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello'], model: 'explicit-embeddings-model');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'explicit-embeddings-model'
                && ! array_key_exists('dimensions', $body);
        });
    }

    public function testAutomaticEmbeddingFakesRequireDimensionsWhenTheModelDimensionsAreUnknown(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate fake embeddings without positive dimensions');
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
        ]]);

        Embeddings::fake();

        Embeddings::for(['Hello'])->generate(provider: 'openai-compatible');
    }

    public function testEmbeddingsRejectResponsesWithMissingVectors(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('expected number of embeddings');
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }

    public function testEmbeddingsRejectMalformedVectors(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('invalid embedding vector');
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => ['invalid']]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Server overloaded']], 503)]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }

    public function testEmbeddingsErrorIn200ResponseThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI-compatible Error');
        Http::fake(['*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'The model does not exist.',
            ],
        ])]);

        Ai::instance('openai-compatible')->embeddings(['Hello']);
    }
}
