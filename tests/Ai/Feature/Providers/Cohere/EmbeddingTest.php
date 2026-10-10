<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

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
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testEmbeddingsRequestIncludesModelTextsInputTypeAndEmbeddingTypes(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'embed-v4.0'
                && $body['texts'] === ['Hello world']
                && $body['input_type'] === 'search_document'
                && $body['embedding_types'] === ['float']
                && $request->url() === 'https://api.cohere.com/v2/embed';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(1, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('cohere', $response->meta->provider);
        $this->assertSame('embed-v4.0', $response->meta->model);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsUseDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'embed-v4.0');
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
            'meta' => ['billed_units' => ['input_tokens' => 20]],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'cohere', model: 'embed-v4.0');

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
    }

    public function testEmbeddingsRequestIncludesProviderOptionsAndLetsThemOverrideDefaultInputType(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['input_type' => 'search_query', 'truncate' => 'END'])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['input_type'] === 'search_query'
                && $body['truncate'] === 'END'
                && $body['model'] === 'embed-v4.0'
                && $body['texts'] === ['Hello'];
        });
    }

    public function testProviderOptionsCannotOverrideFrameworkControlledKeys(): void
    {
        Http::fake(['*' => $this->fakeCohereEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['model' => 'hijacked', 'texts' => ['hijacked']])
            ->generate(provider: 'cohere', model: 'embed-v4.0');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'embed-v4.0' && $body['texts'] === ['Hello'];
        });
    }

    public function testEmbeddingsThrowWhenTheApiReturnsAnError(): void
    {
        $this->expectException(RequestException::class);
        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
    }

    /**
     * Create a Cohere embedding response.
     */
    protected function fakeCohereEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
            'meta' => ['billed_units' => ['input_tokens' => 10]],
        ]);
    }
}
