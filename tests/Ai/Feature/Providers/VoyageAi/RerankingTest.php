<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\VoyageAi;

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
     * Configure the VoyageAI credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.voyageai.key', 'test-key');
    }

    #[DataProvider('queries')]
    public function testRerankingRequestIncludesModelQueryAndDocuments(string $query): void
    {
        Http::fake(['*' => $this->fakeVoyageRerankingResponse()]);

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank($query, provider: 'voyageai', model: 'rerank-2.5-lite');

        Http::assertSent(function (Request $request) use ($query): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'rerank-2.5-lite'
                && ($body['query'] ?? null) === $query
                && $body['documents'] === ['Hypervel is a PHP framework', 'React is a JS library']
                && ! array_key_exists('top_k', $body)
                && $request->url() === 'https://api.voyageai.com/v1/rerank';
        });
    }

    /**
     * Provide ordinary and zero-valued search queries.
     */
    public static function queries(): array
    {
        return [['What is Hypervel?'], ['0']];
    }

    public function testRerankingRequestIncludesTopKWhenLimitSet(): void
    {
        Http::fake(['*' => $this->fakeVoyageRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->limit(2)
            ->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['top_k'] === 2);
    }

    public function testRerankingResponseIsCorrectlyParsedIntoRankedDocuments(): void
    {
        Http::fake(['*' => $this->fakeVoyageRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'voyageai', model: 'rerank-2.5-lite');

        $this->assertCount(2, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
        $this->assertSame(0, $response->first()->index);
        $this->assertSame('Hypervel is a PHP framework', $response->first()->document);
        $this->assertSame(0.95, $response->first()->score);
        $this->assertSame('voyageai', $response->meta->provider);
        $this->assertSame('rerank-2.5-lite', $response->meta->model);
    }

    public function testRerankingRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeVoyageRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
        ]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
    }

    public function testRerankingOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Service unavailable'], 503),
        ]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
    }

    public function testRerankingHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.voyageai.com/*' => Http::response(['detail' => 'Invalid model'], 400),
        ]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'voyageai', model: 'rerank-2.5-lite');
    }

    /**
     * Create a VoyageAI reranking response.
     */
    protected function fakeVoyageRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'object' => 'list',
            'data' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
            'model' => 'rerank-2.5-lite',
            'usage' => ['total_tokens' => 25],
        ]);
    }

    public function testRerankingResponseReportsTheTotalTokens(): void
    {
        Http::fake(['*' => $this->fakeVoyageRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'voyageai', model: 'rerank-2.5-lite');

        $this->assertSame(25, $response->usage->inputTokens);
        $this->assertNull($response->usage->searchUnits);
    }
}
