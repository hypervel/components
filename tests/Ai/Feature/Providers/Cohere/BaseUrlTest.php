<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Reranking;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class BaseUrlTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    /**
     * Create a Cohere embedding response.
     */
    protected function fakeCohereBaseUrlEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 5]],
        ]);
    }

    /**
     * Create a Cohere reranking response.
     */
    protected function fakeCohereBaseUrlRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'results' => [['index' => 0, 'relevance_score' => 0.9]],
            'meta' => ['billed_units' => ['search_units' => 1]],
        ]);
    }

    public function testCohereEmbeddingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.cohere' => [
            ...config('ai.providers.cohere'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v2',
        ]]);

        Http::fake(['*' => $this->fakeCohereBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v2/embed');
    }

    public function testCohereRerankingRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.cohere' => [
            ...config('ai.providers.cohere'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v2',
        ]]);

        Http::fake(['*' => $this->fakeCohereBaseUrlRerankingResponse()]);

        Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v2/rerank');
    }

    public function testCohereTextRequestsUseTheConfiguredBaseUrl(): void
    {
        config(['ai.providers.cohere' => [
            ...config('ai.providers.cohere'),
            'key' => 'test-key',
            'url' => 'http://localhost:8080/v2',
        ]]);

        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v2/chat');
    }

    public function testCohereRequestsFallBackToTheDefaultBaseUrl(): void
    {
        config(['ai.providers.cohere' => [
            ...config('ai.providers.cohere'),
            'key' => 'test-key',
        ]]);

        Http::fake(['*' => $this->fakeCohereBaseUrlEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cohere.com/v2/embed');
    }
}
