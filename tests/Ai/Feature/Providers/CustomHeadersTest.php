<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

function fakeCustomHeadersEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

class CustomHeadersTest extends TestCase
{
    public function testConfiguredHeadersAreSentWithProviderRequests(): void
    {
        config(['ai.providers.voyageai' => [
            ...config('ai.providers.voyageai'),
            'key' => 'test-key',
            'headers' => ['X-Session-Affinity' => 'abc-123'],
        ]]);

        Http::fake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $request): bool => $request->header('X-Session-Affinity') === ['abc-123']);
    }

    public function testConfiguredHeadersOverrideGatewayDefaultHeaders(): void
    {
        config(['ai.providers.voyageai' => [
            ...config('ai.providers.voyageai'),
            'key' => 'test-key',
            'headers' => ['authorization' => 'Bearer proxy-token'],
        ]]);

        Http::fake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer proxy-token']);
    }
}
