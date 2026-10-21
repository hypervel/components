<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Gateway\CohereGateway;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Reranking;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\PendingRequest;
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
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    #[DataProvider('queries')]
    public function testRerankingRequestIncludesModelQueryAndDocuments(string $query): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank($query, provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(function (Request $request) use ($query): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'rerank-v3.5'
                && ($body['query'] ?? null) === $query
                && $body['documents'] === ['Hypervel is a PHP framework', 'React is a JS library']
                && ! array_key_exists('top_n', $body)
                && $request->url() === 'https://api.cohere.com/v2/rerank';
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
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B', 'Doc C'])
            ->limit(2)
            ->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['top_n'] === 2);
    }

    public function testRerankingResponseIsCorrectlyParsedIntoRankedDocuments(): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        $this->assertCount(2, $response);
        $this->assertInstanceOf(RankedDocument::class, $response->first());
        $this->assertSame(0, $response->first()->index);
        $this->assertSame('Hypervel is a PHP framework', $response->first()->document);
        $this->assertSame(0.95, $response->first()->score);
        $this->assertSame('cohere', $response->meta->provider);
        $this->assertSame('rerank-v3.5', $response->meta->model);
    }

    public function testRerankingRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testRerankingUsesDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'rerank-v4.0-pro');
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
            ->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

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
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
    }

    public function testRerankingRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake(['api.cohere.com/*' => Http::response(['message' => 'rate limit exceeded'], 429)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
    }

    public function testRerankingOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake(['api.cohere.com/*' => Http::response(['message' => 'service unavailable'], 503)]);

        Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
    }

    /**
     * Create a Cohere reranking response.
     */
    protected function fakeCohereRerankingResponse(): PromiseInterface
    {
        return Http::response([
            'results' => [
                ['index' => 0, 'relevance_score' => 0.95],
                ['index' => 1, 'relevance_score' => 0.12],
            ],
        ]);
    }

    public function testRerankingRequestUsesTheConfiguredTimeout(): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        $spy = new class extends CohereGateway {
            public array $timeouts = [];

            /**
             * Record the timeout used to create a client.
             */
            protected function client(Provider $provider, int $timeout = 30): PendingRequest
            {
                $this->timeouts[] = $timeout;

                return parent::client($provider, $timeout);
            }
        };

        Ai::instance('cohere')->useRerankingGateway($spy);

        Reranking::of(['Doc A', 'Doc B'])->timeout(45)->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

        $this->assertSame([45], $spy->timeouts);
    }

    public function testRerankingResponseReportsTheBilledUnits(): void
    {
        Http::fake(['*' => Http::response([
            'results' => [['index' => 0, 'relevance_score' => 0.95]],
            'meta' => [
                'billed_units' => ['input_tokens' => 320, 'search_units' => 2.0],
                'tokens' => ['input_tokens' => 298],
            ],
        ])]);

        $response = Reranking::of(['Hypervel is a PHP framework'])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        $this->assertSame(320, $response->usage->inputTokens);
        $this->assertSame(2.0, $response->usage->searchUnits);
    }

    public function testRerankingResponseLeavesTheSearchUnitsNullWhenCohereOmitsTheMeta(): void
    {
        Http::fake(['*' => $this->fakeCohereRerankingResponse()]);

        $response = Reranking::of(['Hypervel is a PHP framework', 'React is a JS library'])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertNull($response->usage->searchUnits);
    }
}
