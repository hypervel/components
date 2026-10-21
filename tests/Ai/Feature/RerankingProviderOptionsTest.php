<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Reranking;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

function fakeRerankingResponse(): array
{
    return ['results' => [['index' => 0, 'relevance_score' => 0.9]]];
}

class RerankingProviderOptionsTest extends TestCase
{
    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.providers.cohere.key' => 'test-key',
            'ai.providers.voyageai.key' => 'test-key',
        ]);
    }

    public function testFlatProviderOptionsAreSentOnTheRerankingRequest(): void
    {
        Http::fake(['*' => Http::response(fakeRerankingResponse())]);

        Reranking::of(['Hypervel is a PHP framework'])
            ->withProviderOptions(['max_tokens_per_doc' => 512])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
    }

    public function testProviderOptionsMayNotOverrideTheCoreRerankingRequestPayload(): void
    {
        Http::fake(['*' => Http::response(fakeRerankingResponse())]);

        Reranking::of(['Hypervel is a PHP framework'])
            ->withProviderOptions(['model' => 'hijacked', 'query' => 'hijacked'])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'rerank-v3.5' && $body['query'] === 'What is Hypervel?';
        });
    }

    public function testClosureProviderOptionsReceiveTheResolvedRerankingProvider(): void
    {
        Http::fake(['*' => Http::response(fakeRerankingResponse())]);

        $seen = [];

        Reranking::of(['Hypervel is a PHP framework'])
            ->withProviderOptions(function (Provider $provider) use (&$seen): array {
                $seen[] = $provider->driver();

                return ['max_tokens_per_doc' => 512];
            })
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        $this->assertSame(['cohere'], $seen);

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
    }

    public function testFalsyProviderOptionsAreNotDroppedFromTheRerankingRequest(): void
    {
        Http::fake(['*' => Http::response(fakeRerankingResponse())]);

        Reranking::of(['Hypervel is a PHP framework'])
            ->withProviderOptions(['return_documents' => false])
            ->rerank('What is Hypervel?', provider: 'cohere', model: 'rerank-v3.5');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return array_key_exists('return_documents', $body) && $body['return_documents'] === false;
        });
    }

    public function testTheLimitStillWinsOverAVoyageTopKProviderOption(): void
    {
        Http::fake(['*' => Http::response(['data' => [['index' => 0, 'relevance_score' => 0.9]]])]);

        Reranking::of(['Hypervel is a PHP framework'])
            ->limit(1)
            ->withProviderOptions(['top_k' => 99, 'truncation' => false])
            ->rerank('What is Hypervel?', provider: 'voyageai', model: 'rerank-2.5-lite');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['top_k'] === 1 && $body['truncation'] === false;
        });
    }
}
