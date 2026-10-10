<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class EmbeddingTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testEmbeddingsRequestIncludesModelInputAndDimensions(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'text-embedding-3-small'
                && $body['input'] === ['Hello world']
                && $body['dimensions'] === 768
                && $request->url() === 'https://api.openai.com/v1/embeddings';
        });
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'openai', model: 'text-embedding-3-small');

        $this->assertCount(1, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('openai', $response->meta->provider);
        $this->assertSame('text-embedding-3-small', $response->meta->model);
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['prompt_tokens' => 20],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'openai', model: 'text-embedding-3-small');

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsUseDefaultModelWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'text-embedding-3-small');
    }

    public function testEmbeddingsDefaultTo1536DimensionsWhenNoneSpecified(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['dimensions'] === 1536);
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['encoding_format' => 'base64', 'user' => 'tester'])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['encoding_format'] === 'base64'
                && $body['user'] === 'tester'
                && $body['model'] === 'text-embedding-3-small';
        });
    }

    public function testProviderOptionsCannotOverrideFrameworkControlledKeys(): void
    {
        Http::fake(['*' => $this->fakeOpenAiEmbeddingResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['model' => 'hijacked', 'input' => ['hijacked'], 'dimensions' => 1])
            ->generate(provider: 'openai', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'text-embedding-3-small'
                && $body['input'] === ['Hello']
                && $body['dimensions'] === 1536;
        });
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
        ], 429)]);

        $this->expectException(RateLimitedException::class);
        Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'server_error', 'message' => 'The server is currently overloaded. Please try again later.'],
        ], 503)]);

        $this->expectException(ProviderOverloadedException::class);
        Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Unauthorized'],
        ], 401)]);

        $this->expectException(RequestException::class);
        Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
    }

    /**
     * Create an embedding response.
     */
    private function fakeOpenAiEmbeddingResponse(): PromiseInterface
    {
        return Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
            'usage' => ['prompt_tokens' => 10],
        ]);
    }
}
