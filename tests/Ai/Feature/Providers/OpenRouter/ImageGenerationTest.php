<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageGenerationTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    /**
     * Create an image generation response.
     */
    protected function fakeOpenRouterImageResponse(string $mimeType = 'image/png', string $content = 'fake-image'): PromiseInterface
    {
        return Http::response([
            'choices' => [[
                'message' => [
                    'images' => [[
                        'image_url' => [
                            'url' => "data:{$mimeType};base64," . base64_encode($content),
                        ],
                    ]],
                ],
            ]],
            'model' => 'google/gemini-2.5-flash-image',
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
            ],
        ]);
    }

    public function testImageRequestIncludesModelMessagesAndModalities(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'chat/completions')
                && $body['model'] === 'google/gemini-2.5-flash-image'
                && $body['modalities'] === ['image']
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'] === 'A blue circle';
        });
    }

    public function testImageRequestMapsSizeToImageConfigAspectRatio(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')->landscape()->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'image_config.aspect_ratio') === '3:2'
                && ! array_key_exists('image_size', data_get($body, 'image_config', []));
        });
    }

    #[DataProvider('imageQualities')]
    public function testImageRequestMapsQualityToImageConfigImageSize(string $quality, string $expectedSize): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')->quality($quality)->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request) use ($expectedSize): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'image_config.image_size') === $expectedSize;
        });
    }

    /**
     * Provide the supported quality mappings.
     */
    public static function imageQualities(): array
    {
        return [
            'low maps to 1K' => ['low', '1K'],
            'medium maps to 2K' => ['medium', '2K'],
            'high maps to 4K' => ['high', '4K'],
        ];
    }

    public function testImageRequestOmitsImageConfigWhenNoSizeOrQualityIsGiven(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('image_config', $body);
        });
    }

    public function testImageResponseIsCorrectlyParsed(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse('image/png', 'fake-image')]);

        $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(1, $response->images);
        $this->assertSame(base64_encode('fake-image'), $response->images->first()->image);
        $this->assertSame('image/png', $response->images->first()->mime);
        $this->assertSame('openrouter', $response->meta->provider);
        $this->assertSame('google/gemini-2.5-flash-image', $response->meta->model);
    }

    public function testUsageTokensAreParsedFromResponse(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(20, $response->usage->outputTokens);
    }

    public function testMultipleImagesInResponseAreAllReturned(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'images' => [
                            ['image_url' => ['url' => 'data:image/png;base64,' . base64_encode('first')]],
                            ['image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('second')]],
                        ],
                    ],
                ]],
            ]),
        ]);

        $response = Image::of('Two images')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(2, $response->images);
        $this->assertSame(base64_encode('first'), $response->images[0]->image);
        $this->assertSame('image/png', $response->images[0]->mime);
        $this->assertSame(base64_encode('second'), $response->images[1]->image);
        $this->assertSame('image/jpeg', $response->images[1]->mime);
    }

    public function testEmptyImagesCollectionReturnedWhenResponseContainsNoImages(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'I cannot generate that image.'],
                ]],
            ]),
        ]);

        $response = Image::of('A prompt')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(0, $response->images);
    }

    public function testAttachmentsAreSentAsImageUrlContentParts(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        $attachment = new Base64Image(base64_encode('source-image'), 'image/jpeg');

        Image::of('Edit this image')->attachments([$attachment])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'];

            return is_array($content)
                && $content[0]['type'] === 'text'
                && $content[0]['text'] === 'Edit this image'
                && $content[1]['type'] === 'image_url'
                && str_starts_with((string) $content[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }

    public function testRemoteImageAttachmentsArePassedAsUrlWithoutDownloading(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        $attachment = new RemoteImage('https://example.com/photo.jpg', 'image/jpeg');

        Image::of('Edit this image')->attachments([$attachment])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'];

            return is_array($content)
                && $content[1]['type'] === 'image_url'
                && $content[1]['image_url']['url'] === 'https://example.com/photo.jpg';
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testImageRateLimitResponseThrowsRateLimitedException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);
        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
    }

    public function testImageOverloadedResponseThrowsProviderOverloadedException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        $this->expectException(ProviderOverloadedException::class);
        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
    }

    public function testImageHttpErrorResponseThrowsRequestException(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid model',
                ],
            ], 400),
        ]);

        $this->expectException(RequestException::class);
        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');
    }

    public function testFalsyProviderOptionsAreNotFilteredOutOfTheImageRequest(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A blue circle')
            ->withProviderOptions(['stream' => false])
            ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['stream'] === false);
    }

    public function testImageErrorInSuccessfulHttpResponseThrows(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Provider disconnected']]],
        ])]);

        $this->expectException(ProviderOverloadedException::class);
        $this->expectExceptionMessage('Provider disconnected');
        Image::of('A blue circle')->generate(provider: 'openrouter');
    }
}
