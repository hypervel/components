<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

class ImageGenerationTest extends TestCase
{
    /**
     * Configure the OpenAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testImageRequestDoesNotIncludeQualityWhenNotSpecified(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-2');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'dall-e-2'
                && ! array_key_exists('quality', $body);
        });
    }

    public function testImageRequestDoesNotIncludeModerationForNonGptImageModels(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'dall-e-3'
                && ! array_key_exists('moderation', $body);
        });
    }

    public function testImageRequestIncludesModerationLowForGptImageModels(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-image-1'
                && $body['moderation'] === 'low';
        });
    }

    public function testImageRequestIncludesQualityWhenExplicitlySpecified(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->quality('high')->generate(provider: 'openai', model: 'dall-e-3');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['quality'] === 'high'
                && ! array_key_exists('moderation', $body);
        });
    }

    public function testImageRequestIncludesSizeWhenSpecified(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->square()->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['size'] === '1024x1024';
        });
    }

    public function testImageRequestDoesNotIncludeSizeWhenNotSpecified(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('size', $body);
        });
    }

    public function testImageResponseIncludesUsageTokensWhenReturnedByGptImage(): void
    {
        Http::fake([
            '*' => Http::response([
                'output_format' => 'webp',
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 41,
                    'output_tokens' => 1024,
                    'input_tokens_details' => [
                        'text_tokens' => 41,
                        'image_tokens' => 0,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->withProviderOptions(['output_format' => 'webp'])->generate(provider: 'openai', model: 'gpt-image-1');

        $this->assertSame(41, $response->usage->inputTokens);
        $this->assertSame(1024, $response->usage->outputTokens);
        $this->assertSame('image/webp', $response->images->first()->mime);
    }

    public function testImageResponseReportsCachedTokensWithinTheInputTokens(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 1024,
                    'input_tokens_details' => [
                        'cached_tokens' => 30,
                        'text_tokens' => 70,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(30, $response->usage->cacheReadInputTokens);
        $this->assertSame(1024, $response->usage->outputTokens);
    }

    public function testImageResponseDefaultsToZeroUsageWhenNotReturnedByDalle(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
    }

    public function testImageGenerationRequestAddsResponseFormatB64JsonForDallEModels(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['response_format'] ?? null) === 'b64_json';
        });
    }

    public function testImageGenerationRequestOmitsResponseFormatForGptImageModels(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function testImageRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
    }

    public function testImageOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
    }

    public function testImageHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid model',
                ],
            ], 400),
        ]);

        Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');
    }

    public function testImageResponseReportsTheImageTokenDetailsReturnedByGptImage(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 187,
                    'output_tokens' => 1481,
                    'input_tokens_details' => [
                        'text_tokens' => 41,
                        'image_tokens' => 146,
                    ],
                    'output_tokens_details' => [
                        'text_tokens' => 209,
                        'image_tokens' => 1272,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'openai', model: 'gpt-image-1');

        $this->assertSame(146, $response->usage->imageInputTokens);
        $this->assertSame(1272, $response->usage->imageOutputTokens);
    }

    public function testImageResponseLeavesTheImageTokenDetailsNullWhenDalleOmitsThem(): void
    {
        Http::fake([
            '*' => fakeOpenAiImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'openai', model: 'dall-e-3');

        $this->assertNull($response->usage->imageInputTokens);
        $this->assertNull($response->usage->imageOutputTokens);
    }
}

/**
 * Build an image response.
 */
function fakeOpenAiImageResponse(): PromiseInterface
{
    return Http::response([
        'data' => [[
            'b64_json' => base64_encode('fake-image'),
        ]],
    ]);
}
