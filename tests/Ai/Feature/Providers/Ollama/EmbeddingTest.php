<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class EmbeddingTest extends TestCase
{
    /**
     * Configure the local Ollama provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
        $app->make('config')->set('ai.providers.ollama.url', 'http://localhost:11434');
    }

    public function testEmbeddingsRequestIncludesModelAndInput(): void
    {
        Http::fake(['*' => $this->fakeOllamaEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'nomic-embed-text'
                && $body['input'] === ['Hello world']
                && str_ends_with($request->url(), '/api/embed');
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeOllamaEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        $this->assertCount(1, $response->embeddings);
        $this->assertCount(3, $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('ollama', $response->meta->provider);
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'nomic-embed-text',
            'embeddings' => [
                [0.1, 0.2, 0.3],
                [0.4, 0.5, 0.6],
            ],
            'prompt_eval_count' => 20,
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        $this->assertCount(2, $response->embeddings);
    }

    public function testEmbeddingsRequestSendsBearerTokenWhenKeyIsSet(): void
    {
        config(['ai.providers.ollama' => [
            ...config('ai.providers.ollama'),
            'key' => 'test-key',
        ]]);

        Http::fake(['*' => $this->fakeOllamaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsRequestSendsNoAuthorizationHeaderWhenKeyIsEmpty(): void
    {
        Http::fake(['*' => $this->fakeOllamaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'rate limit exceeded',
            ], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'server overloaded',
            ], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'error' => 'model not found',
            ], 400),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => $this->fakeOllamaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['truncate' => false, 'keep_alive' => '5m'])
            ->generate(provider: 'ollama', model: 'nomic-embed-text');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['truncate'] === false
                && $body['keep_alive'] === '5m'
                && $body['model'] === 'nomic-embed-text';
        });
    }

    /**
     * Create an embeddings response.
     */
    protected function fakeOllamaEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'model' => 'nomic-embed-text',
            'embeddings' => [
                [0.1, 0.2, 0.3],
            ],
            'prompt_eval_count' => 10,
        ]);
    }
}
