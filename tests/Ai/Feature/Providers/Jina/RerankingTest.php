<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Jina;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RerankingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.jina.key', 'test-key');
    }

    #[DataProvider('queries')]
    public function testRerankingRequestIncludesModelQueryAndDocuments(string $query): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank($query, provider: 'jina', model: 'jina-reranker-v3');

        Http::assertSent(function (Request $request) use ($query): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'jina-reranker-v3'
                && ($body['query'] ?? null) === $query
                && $body['documents'] === ['Hypervel is a PHP framework', 'React is a JS library']
                && ! array_key_exists('top_n', $body)
                && $request->url() === 'https://api.jina.ai/v1/rerank';
        });
    }

    /**
     * Provide valid reranking queries.
     */
    public static function queries(): array
    {
        return [['What is Hypervel?'], ['0']];
    }

    public function testRerankingRequestIncludesTopNWhenLimitSet(): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->limit(2)
            ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['top_n'] === 2);
    }

    public function testRerankingResponseIsCorrectlyParsedIntoRankedDocuments(): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'jina', model: 'jina-reranker-v3');

        $this->assertCount(2, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
        $this->assertSame(0, $response->first()->index);
        $this->assertSame('Hypervel is a PHP framework', $response->first()->document);
        $this->assertSame(0.95, $response->first()->score);
        $this->assertSame('jina', $response->meta->provider);
        $this->assertSame('jina-reranker-v3', $response->meta->model);
    }

    public function testRerankingRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testRerankingUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'jina-reranker-v3.5');
    }

    public function testRerankingMapsDocumentsByIndexWhenResultsAreReturnedOutOfOrder(): void
    {
        Http::fake(['*' => Http::response([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.91],
                ['index' => 0, 'relevance_score' => 0.42],
                ['index' => 1, 'relevance_score' => 0.10],
            ],
            'model' => 'jina-reranker-v3',
            'usage' => ['total_tokens' => 25],
        ])]);

        $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

        $ranked = $response->collect();

        $this->assertSame(2, $ranked[0]->index);
        $this->assertSame('Doc C', $ranked[0]->document);
        $this->assertSame(0.91, $ranked[0]->score);
        $this->assertSame(0, $ranked[1]->index);
        $this->assertSame('Doc A', $ranked[1]->document);
    }

    public function testRerankingThrowsWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.jina.ai/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
    }

    public function testRerankingOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['api.jina.ai/*' => Http::response(['detail' => 'service unavailable'], 503)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
    }

    /**
     * Create a reranking response.
     */
    protected function fakeJinaRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
            'model' => 'jina-reranker-v3',
            'usage' => ['total_tokens' => 25],
        ]);
    }

    public function testRerankingResponseReportsTheTotalTokens(): void
    {
        Http::fake(['*' => $this->fakeJinaRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'jina', model: 'jina-reranker-v3');

        $this->assertSame(25, $response->usage->inputTokens);
        $this->assertNull($response->usage->searchUnits);
    }
}
