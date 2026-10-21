<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Reranking;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RerankingTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    #[DataProvider('queries')]
    public function testRerankingRequestIncludesModelQueryAndDocuments(string $query): void
    {
        Http::fake(['*' => $this->fakeOpenRouterRerankingResponse()]);

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank($query, provider: 'openrouter', model: 'cohere/rerank-v3.5');

        Http::assertSent(function (Request $request) use ($query): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'cohere/rerank-v3.5'
                && ($body['query'] ?? null) === $query
                && $body['documents'] === ['Hypervel is a PHP framework', 'React is a JS library']
                && ! array_key_exists('top_n', $body)
                && $request->url() === 'https://openrouter.ai/api/v1/rerank';
        });
    }

    /**
     * Provide ordinary and zero-valued search queries.
     */
    public static function queries(): array
    {
        return [['What is Hypervel?'], ['0']];
    }

    public function testRerankingRequestIncludesTopNWhenLimitSet(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->limit(2)
            ->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['top_n'] === 2);
    }

    public function testRerankingUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'cohere/rerank-4-pro');
    }

    public function testRerankingMapsDocumentsByIndexWhenResultsAreReturnedOutOfOrder(): void
    {
        Http::fake(['*' => Http::response([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.91],
                ['index' => 0, 'relevance_score' => 0.42],
                ['index' => 1, 'relevance_score' => 0.10],
            ],
        ])]);

        $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

        $ranked = $response->collect();

        $this->assertSame(2, $ranked[0]->index);
        $this->assertSame('Doc C', $ranked[0]->document);
        $this->assertSame(0.91, $ranked[0]->score);
        $this->assertSame(0, $ranked[1]->index);
        $this->assertSame('Doc A', $ranked[1]->document);
        $this->assertSame('openrouter', $response->meta->provider);
        $this->assertSame('cohere/rerank-v3.5', $response->meta->model);
    }

    public function testRerankingErrorIn200ResponseThrowsAiException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'The model does not exist.'],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenRouter Error');
        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['message' => 'rate limit exceeded'], 429)]);

        $this->expectException(RateLimitedException::class);
        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');
    }

    /**
     * Create a reranking response.
     */
    protected function fakeOpenRouterRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
        ]);
    }

    public function testRerankingResponseReportsTheTotalTokensAndSearchUnits(): void
    {
        Http::fake(['*' => Http::response([
            'results' => [['index' => 0, 'relevance_score' => 0.95]],
            'usage' => ['total_tokens' => 320, 'search_units' => 1],
        ])]);

        $response = Reranking::of(['Doc A'])->rerank('query', provider: 'openrouter', model: 'cohere/rerank-v3.5');

        $this->assertSame(320, $response->usage->inputTokens);
        $this->assertSame(1.0, $response->usage->searchUnits);
    }
}
