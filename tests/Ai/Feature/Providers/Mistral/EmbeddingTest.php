<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

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
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testEmbeddingsRequestIncludesModelAndInput(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'mistral-embed'
                && $body['input'] === ['Hello world']
                && $request->url() === 'https://api.mistral.ai/v1/embeddings';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

        $this->assertCount(1, $response->embeddings);
        $this->assertCount(3, $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 0, 'total_tokens' => 20],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'mistral', model: 'mistral-embed');

        $this->assertCount(2, $response->embeddings);
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'object' => 'error',
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'object' => 'error',
                'message' => 'The server is currently overloaded.',
                'type' => 'server_error',
            ], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'object' => 'error',
                'message' => 'Invalid API key',
                'type' => 'authentication_error',
            ], 401),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => $this->fakeEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['output_dimension' => 256, 'output_dtype' => 'float'])
            ->generate(provider: 'mistral', model: 'mistral-embed');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['output_dimension'] === 256
                && $body['output_dtype'] === 'float'
                && $body['model'] === 'mistral-embed';
        });
    }

    /**
     * Create an embeddings response.
     */
    protected function fakeEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0, 'total_tokens' => 10],
        ]);
    }
}
