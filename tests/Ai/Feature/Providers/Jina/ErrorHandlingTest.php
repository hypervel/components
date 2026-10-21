<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Jina;

use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Reranking;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class ErrorHandlingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.jina.key', 'test-key');
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Service overloaded'], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
    }

    public function testRerankingOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Service overloaded'], 503),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
    }

    public function testRerankingHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.jina.ai/*' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
    }
}
