<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

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
     * Configure the Azure OpenAI deployment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://my-resource.cognitiveservices.azure.com',
            'deployment' => 'gpt-4o',
            'embedding_deployment' => 'text-embedding-3-small',
        ]);
    }

    public function testEmbeddingsRequestIncludesModelInputAndDimensions(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        Embeddings::for(['Hello world'])->dimensions(1536)->generate(provider: 'azure', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'text-embedding-3-small'
                && $body['input'] === ['Hello world']
                && $body['dimensions'] === 1536;
        });
    }

    public function testEmbeddingsRequestUsesTheV1EmbeddingsEndpoint(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/openai/v1/embeddings'));
    }

    public function testEmbeddingsResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        $response = Embeddings::for(['Hello world'])->generate(provider: 'azure', model: 'text-embedding-3-small');

        $this->assertCount(1, $response->embeddings);
        $this->assertCount(3, $response->embeddings[0]);
        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame('azure', $response->meta->provider);
    }

    public function testEmbeddingsRequestSendsApiKeyHeader(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('api-key', 'test-key'));
    }

    public function testEmbeddingsRequestDoesNotIncludeApiVersionQueryParameter(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'api-version'));
    }

    public function testMultipleInputsReturnMultipleEmbeddings(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'text-embedding-3-small',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['prompt_tokens' => 20],
        ])]);

        $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'azure', model: 'text-embedding-3-small');

        $this->assertCount(2, $response->embeddings);
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
    }

    public function testEmbeddingsHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid input',
                ],
            ], 400),
        ]);

        Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => $this->fakeAzureEmbeddingsResponse()]);

        Embeddings::for(['Hello'])
            ->withProviderOptions(['encoding_format' => 'base64', 'user' => 'tester'])
            ->generate(provider: 'azure', model: 'text-embedding-3-small');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['encoding_format'] === 'base64'
                && $body['user'] === 'tester'
                && $body['model'] === 'text-embedding-3-small';
        });
    }

    /**
     * Build an embeddings response.
     */
    private function fakeAzureEmbeddingsResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'embd-123',
            'object' => 'list',
            'model' => 'text-embedding-3-small',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 10],
        ]);
    }
}
