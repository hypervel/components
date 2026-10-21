<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Jina;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Reranking;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class BaseUrlTest extends TestCase
{
    /**
     * Create an embeddings response.
     */
    protected function fakeJinaBaseUrlEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'model' => 'jina-embeddings-v4',
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['total_tokens' => 5],
        ]);
    }

    /**
     * Create a reranking response.
     */
    protected function fakeJinaBaseUrlRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'model' => 'jina-reranker-v3',
            'results' => [['index' => 0, 'relevance_score' => 0.9]],
        ]);
    }

    public function testJinaEmbeddingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.jina' => [
            ...config('ai.providers.jina'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => $this->fakeJinaBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/embeddings');
    }

    public function testJinaRerankingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.jina' => [
            ...config('ai.providers.jina'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => $this->fakeJinaBaseUrlRerankingResponse()]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/rerank');
    }

    public function testJinaRequestsFallBackToTheDefaultBaseUrl(): void
    {
        config(['ai.providers.jina' => array_diff_key(
            [...config('ai.providers.jina'), 'key' => 'test-key'],
            ['url' => null],
        )]);

        Http::fake(['*' => $this->fakeJinaBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.jina.ai/v1/embeddings');
    }
}
