<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Reranking;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Rate limit exceeded'], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Service overloaded'], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Rate limit exceeded'], 429),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
    }

    public function testRerankingOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Service overloaded'], 503),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
    }

    public function testRerankingHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
    }

    public function testTextRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Rate limit exceeded'], 429),
        ]);

        agent()->prompt('Hello', provider: 'cohere');
    }

    public function testTextOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'Service overloaded'], 503),
        ]);

        agent()->prompt('Hello', provider: 'cohere');
    }

    public function testTextHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.cohere.com/*' => Http::response(['message' => 'invalid api token'], 401),
        ]);

        agent()->prompt('Hello', provider: 'cohere');
    }

    public function testTextResponseWithoutAMessageThrowsAiException(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Cohere Error: Something went wrong');
        Http::fake([
            'api.cohere.com/*' => Http::response(['id' => 'abc', 'message' => 'Something went wrong'], 200),
        ]);

        agent()->prompt('Hello', provider: 'cohere');
    }
}
