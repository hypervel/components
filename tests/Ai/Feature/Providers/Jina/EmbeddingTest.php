<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Jina;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class EmbeddingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.jina.key', 'test-key');
    }

    public function testEmbeddingsRequestIncludesModelInputAndDimensions(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'jina-embeddings-v4'
                && $body['input'][0]['text'] === 'Hello world'
                && $body['dimensions'] === 1024
                && $body['task'] === 'retrieval.passage'
                && $request->url() === 'https://api.jina.ai/v1/embeddings';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        $this->assertCount(1, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('jina', $response->meta->provider);
        $this->assertSame('jina-embeddings-v4', $response->meta->model);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsUseDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'jina');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'jina-embeddings-v4');
    }

    public function testEmbeddingsThrowWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'jina-embeddings-v4',
            'object' => 'list',
            'usage' => ['total_tokens' => 20],
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
    }

    public function testEmbeddingsDefaultTo2048DimensionsWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['dimensions'] === 2048);
    }

    public function testEmbeddingsRequestIncludesProviderOptionsAndOverridesDefaultTask(): void
    {
        Http::fake(['*' => $this->fakeJinaEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['task' => 'retrieval.query', 'late_chunking' => true])
            ->generate(provider: 'jina', model: 'jina-embeddings-v4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['task'] === 'retrieval.query'
                && $body['late_chunking'] === true
                && $body['model'] === 'jina-embeddings-v4';
        });
    }

    /**
     * Create an embeddings response.
     */
    protected function fakeJinaEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'model' => 'jina-embeddings-v4',
            'object' => 'list',
            'usage' => ['total_tokens' => 10],
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
        ]);
    }
}
