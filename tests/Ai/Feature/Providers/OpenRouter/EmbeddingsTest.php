<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class EmbeddingsTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testEmbeddingsRequestIsCorrectlyFormatted(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ],
            'usage' => ['prompt_tokens' => 5],
        ])]);

        Ai::instance('openrouter')->embeddings(['Hello world']);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'google/gemini-embedding-001'
                && $body['input'] === ['Hello world']
                && $body['dimensions'] === 1536
                && str_contains($request->url(), 'embeddings');
        });
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEmbeddingsResponseIsCorrectlyParsed(bool $shuffled): void
    {
        $embeddings = [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ];

        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => $shuffled ? array_reverse($embeddings) : $embeddings,
            'usage' => ['prompt_tokens' => 10],
        ])]);

        $response = Ai::instance('openrouter')->embeddings(['Hello', 'World']);

        $this->assertCount(2, $response->embeddings);
        $this->assertSame([0.1, 0.2, 0.3], $response->embeddings[0]);
        $this->assertSame([0.4, 0.5, 0.6], $response->embeddings[1]);
        $this->assertSame(10, $response->usage->inputTokens);
    }

    public function testEmbeddingsRequestSendsBearerToken(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openrouter')->embeddings(['test']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testEmbeddingsRequestUsesOpenrouterBaseUrl(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openrouter')->embeddings(['test']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/embeddings');
    }

    public function testEmbeddingsRequestIncludesProviderOptionsInTheRequestBody(): void
    {
        Http::fake(['*' => Http::response([
            'object' => 'list',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 1],
        ])]);

        Ai::instance('openrouter')->embeddings(
            inputs: ['Hello'],
            providerOptions: ['encoding_format' => 'base64'],
        );

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['encoding_format'] === 'base64'
                && $body['input'] === ['Hello'];
        });
    }

    public function testEmbeddingsRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limited']], 429),
        ]);

        $this->expectException(RateLimitedException::class);
        Ai::instance('openrouter')->embeddings(['Hello']);
    }

    public function testEmbeddingsOverloadedResponseThrowsProviderOverloadedException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'Server overloaded']], 503),
        ]);

        $this->expectException(ProviderOverloadedException::class);
        Ai::instance('openrouter')->embeddings(['Hello']);
    }

    public function testEmbeddingsErrorIn200ResponseThrowsAiException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'The model does not exist.',
                ],
            ]),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenRouter Error');
        Ai::instance('openrouter')->embeddings(['Hello']);
    }
}
