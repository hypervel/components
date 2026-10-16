<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageGenerationTest extends TestCase
{
    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    /**
     * Create a generated image response.
     */
    protected function fakeXaiImageResponse(): PromiseInterface
    {
        return Http::response([
            'data' => [[
                'b64_json' => base64_encode('fake-image'),
            ]],
        ]);
    }

    #[DataProvider('prompts')]
    public function testImageRequestIncludesModelPromptAndB64JsonResponseFormat(string $prompt): void
    {
        Http::fake([
            '*' => $this->fakeXaiImageResponse(),
        ]);

        Image::of($prompt)->generate(provider: 'xai', model: 'grok-imagine-image');

        Http::assertSent(function (Request $request) use ($prompt): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'grok-imagine-image'
                && ($body['prompt'] ?? null) === $prompt
                && $body['response_format'] === 'b64_json'
                && ! array_key_exists('size', $body)
                && ! array_key_exists('quality', $body);
        });
    }

    /**
     * Provide image prompts.
     */
    public static function prompts(): array
    {
        return ['ordinary' => ['A red apple'], 'zero' => ['0']];
    }

    public function testOmitsSizeWhenProvidedBecauseXaiDoesNotSupportIt(): void
    {
        Http::fake([
            '*' => $this->fakeXaiImageResponse(),
        ]);

        Image::of('A red apple')->square()->generate(provider: 'xai', model: 'grok-imagine-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('size', $body);
        });
    }

    public function testOmitsQualityWhenProvidedBecauseXaiDoesNotSupportIt(): void
    {
        Http::fake([
            '*' => $this->fakeXaiImageResponse(),
        ]);

        Image::of('A red apple')->quality('high')->generate(provider: 'xai', model: 'grok-imagine-image');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('quality', $body);
        });
    }

    public function testImageResponseIsCorrectlyParsed(): void
    {
        Http::fake([
            '*' => $this->fakeXaiImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

        $this->assertCount(1, $response->images);
        $this->assertSame(base64_encode('fake-image'), $response->images->first()->image);
        $this->assertSame('image/jpeg', $response->images->first()->mime);
        $this->assertSame('xai', $response->meta->provider);
    }

    public function testAllGeneratedImagesAreReturnedWhenNIsGreaterThanOne(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['b64_json' => base64_encode('image-1'), 'mime_type' => 'image/png'],
                    ['b64_json' => base64_encode('image-2')],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')
            ->withProviderOptions(['n' => 2])
            ->generate(provider: 'xai', model: 'grok-imagine-image');

        $this->assertCount(2, $response->images);
        $this->assertSame(base64_encode('image-1'), $response->images->first()->image);
        $this->assertSame(base64_encode('image-2'), $response->images->last()->image);
        $this->assertSame('image/png', $response->images->first()->mime);
        $this->assertSame('image/jpeg', $response->images->last()->mime);

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['n'] === 2);
    }

    #[DataProvider('editAttachments')]
    public function testAttachmentsAreSentToTheEditEndpoint(Closure $attachments, array $expected): void
    {
        Http::fake(['*' => $this->fakeXaiImageResponse()]);

        Image::of('Make it brighter')->attachments($attachments())
            ->landscape()->quality('high')->withProviderOptions(['n' => 2])
            ->generate(provider: 'xai');

        Http::assertSentCount(1);
        $request = Http::recorded()[0][0];
        $this->assertSame('https://api.x.ai/v1/images/edits', $request->url());
        $body = $request->data();
        $this->assertSame($expected, array_intersect_key($body, array_flip(['image', 'images'])));
        $this->assertSame('Make it brighter', $body['prompt']);
        $this->assertSame('b64_json', $body['response_format']);
        $this->assertSame('3:2', $body['aspect_ratio']);
        $this->assertSame('2k', $body['resolution']);
        $this->assertSame(2, $body['n']);
    }

    /**
     * Provide each image input representation and the multi-image request shape.
     */
    public static function editAttachments(): array
    {
        $path = __DIR__ . '/../../../Fixtures/Images/red.png';
        $dataUrl = 'data:image/png;base64,' . base64_encode(file_get_contents($path));

        return [
            'provider' => [fn (): array => [new ProviderImage('file-123')], ['image' => ['file_id' => 'file-123']]],
            'remote' => [fn (): array => [new RemoteImage('https://example.com/image.png')], ['image' => ['url' => 'https://example.com/image.png']]],
            'base64' => [fn (): array => [new Base64Image(base64_encode('image'), 'image/png')], ['image' => ['url' => 'data:image/png;base64,' . base64_encode('image')]]],
            'local' => [fn (): array => [new LocalImage($path)], ['image' => ['url' => $dataUrl]]],
            'upload' => [fn (): array => [new UploadedFile($path, 'red.png', 'image/png', null, true)], ['image' => ['url' => $dataUrl]]],
            'multiple' => [fn (): array => [new ProviderImage('file-123'), new RemoteImage('https://example.com/image.png')], ['images' => [['file_id' => 'file-123'], ['url' => 'https://example.com/image.png']]]],
        ];
    }

    public function testNonImageAttachmentsAreRejected(): void
    {
        Http::fake(['*' => $this->fakeXaiImageResponse()]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image attachment type');

        Image::of('Make it brighter')->attachments([new LocalDocument('/tmp/document.pdf')])->generate(provider: 'xai');
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake([
            '*' => $this->fakeXaiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testImageRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'api.x.ai/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
    }

    public function testImageOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'api.x.ai/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
    }

    public function testImageHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'api.x.ai/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid model',
                ],
            ], 400),
        ]);

        Image::of('A red apple')->generate(provider: 'xai', model: 'grok-imagine-image');
    }
}
