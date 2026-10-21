<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\VoyageAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Reranking;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

/**
 * Create an embedding response.
 */
function fakeVoyageBaseUrlEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

/**
 * Create a reranking response.
 */
function fakeVoyageBaseUrlRerankingResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['index' => 0, 'relevance_score' => 0.9]],
        'model' => 'rerank-2.5-lite',
        'usage' => ['total_tokens' => 5],
    ]);
}

class BaseUrlTest extends TestCase
{
    public function testVoyageaiEmbeddingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.voyageai' => [
            ...config('ai.providers.voyageai'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/embeddings');
    }

    public function testVoyageaiRerankingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.voyageai' => [
            ...config('ai.providers.voyageai'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v1',
        ]]);

        Http::fake(['*' => fakeVoyageBaseUrlRerankingResponse()]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/rerank');
    }

    public function testVoyageaiRequestsFallBackToTheDefaultBaseUrl(): void
    {
        config(['ai.providers.voyageai' => array_diff_key(
            [...config('ai.providers.voyageai'), 'key' => 'test-key'],
            ['url' => null],
        )]);

        Http::fake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.voyageai.com/v1/embeddings');
    }
}
