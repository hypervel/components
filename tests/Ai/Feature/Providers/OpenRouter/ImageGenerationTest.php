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
            'created' => 1748372400,
            'data' => [[
                'b64_json' => base64_encode($content),
                'media_type' => $mimeType,
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
            ],
        ]);
    }

    public function testImageRequestIsSentToTheImagesEndpointWithModelAndPrompt(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A blue circle')->generate(provider: 'openrouter', model: 'black-forest-labs/flux.2-pro');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://openrouter.ai/api/v1/images'
                && json_decode($request->body(), true) === [
                    'model' => 'black-forest-labs/flux.2-pro',
                    'prompt' => 'A blue circle',
                ];
        });
    }

    public function testImageRequestMapsSizeToAspectRatio(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')->landscape()->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['aspect_ratio'] === '3:2');
    }

    #[DataProvider('imageQualities')]
    public function testImageRequestMapsQualityToResolution(string $quality, string $expectedResolution): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')->quality($quality)->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['resolution'] === $expectedResolution);
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

    public function testImageConfigProviderOptionsAreSentAsTopLevelImageFields(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')
            ->withProviderOptions(['image_config' => ['aspect_ratio' => '21:9', 'image_size' => '2K']])
            ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === [
            'aspect_ratio' => '21:9',
            'resolution' => '2K',
            'model' => 'google/gemini-2.5-flash-image',
            'prompt' => 'A sunset',
        ]);
    }

    public function testImageSizeAndQualityTakePrecedenceOverImageConfigProviderOptions(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('A sunset')
            ->landscape()
            ->quality('high')
            ->withProviderOptions(['image_config' => ['aspect_ratio' => '21:9', 'image_size' => '1K']])
            ->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['aspect_ratio'] === '3:2' && $body['resolution'] === '4K';
        });
    }

    public function testImageResponseIsCorrectlyParsed(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse('image/webp', 'fake-image')]);

        $response = Image::of('A blue circle')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(1, $response->images);
        $this->assertSame(base64_encode('fake-image'), $response->images->first()->image);
        $this->assertSame('image/webp', $response->images->first()->mime);
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
                'data' => [
                    ['b64_json' => base64_encode('first'), 'media_type' => 'image/jpeg'],
                    ['b64_json' => base64_encode('second')],
                ],
            ]),
        ]);

        $response = Image::of('Two images')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(2, $response->images);
        $this->assertSame(base64_encode('first'), $response->images[0]->image);
        $this->assertSame('image/jpeg', $response->images[0]->mime);
        $this->assertSame(base64_encode('second'), $response->images[1]->image);
        $this->assertSame('image/png', $response->images[1]->mime);
    }

    public function testEmptyImagesCollectionReturnedWhenResponseContainsNoImages(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => []])]);

        $response = Image::of('A prompt')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        $this->assertCount(0, $response->images);
    }

    public function testAttachmentsAreSentAsInputReferences(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('Edit these images')->attachments([
            new Base64Image(base64_encode('source-image'), 'image/jpeg'),
            new RemoteImage('https://example.com/photo.jpg', 'image/jpeg'),
        ])->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input_references'] === [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode('source-image')]],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.jpg']],
        ]);

        // The remote reference is passed by URL rather than downloaded.
        Http::assertSentCount(1);
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

    public function testFalsyPromptIsNotFilteredOutOfTheImageRequest(): void
    {
        Http::fake(['openrouter.ai/*' => $this->fakeOpenRouterImageResponse()]);

        Image::of('0')->generate(provider: 'openrouter', model: 'google/gemini-2.5-flash-image');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === [
            'model' => 'google/gemini-2.5-flash-image',
            'prompt' => '0',
        ]);
    }

    public function testImageErrorInSuccessfulHttpResponseThrows(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'error' => ['code' => 502, 'message' => 'Provider disconnected'],
        ])]);

        $this->expectException(ProviderOverloadedException::class);
        $this->expectExceptionMessage('Provider disconnected');
        Image::of('A blue circle')->generate(provider: 'openrouter');
    }
}
