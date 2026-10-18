<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageEditTest extends TestCase
{
    /**
     * Configure the OpenAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    /**
     * Build an edited image response.
     */
    protected function fakeOpenAiImageEditResponse(): PromiseInterface
    {
        return Http::response([
            'data' => [[
                'b64_json' => base64_encode('edited-image'),
            ]],
        ]);
    }

    #[DataProvider('prompts')]
    public function testABase64ImageIsSentToTheEditsEndpointAsTheImageContent(string $prompt): void
    {
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        Image::of($prompt)
            ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'images/edits')
            && $request->hasFile('image[]', 'source-bytes', 'image.png')
            && (array_column($request->data(), 'contents', 'name')['prompt'] ?? null) === $prompt);
    }

    /**
     * Provide ordinary and numeric image prompts.
     */
    public static function prompts(): array
    {
        return [['Make it brighter'], ['0']];
    }

    public function testARemoteImageIsFetchedAndSentAsTheImageContent(): void
    {
        Http::fake([
            'example.com/*' => Http::response('remote-bytes'),
            '*' => $this->fakeOpenAiImageEditResponse(),
        ]);

        Image::of('Make it brighter')
            ->attachments([new RemoteImage('https://example.com/source.png', 'image/png')])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'remote-bytes'));
    }

    public function testALocalImageIsSentAsTheImageContent(): void
    {
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        $path = __DIR__ . '/../../../Fixtures/Images/red.png';

        Image::of('Make it brighter')
            ->attachments([new LocalImage($path, 'image/png')])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', file_get_contents($path)));
    }

    public function testAStoredImageIsSentAsTheImageContent(): void
    {
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        Storage::fake('images');
        Storage::disk('images')->put('source.png', 'stored-bytes');

        Image::of('Make it brighter')
            ->attachments([new StoredImage('source.png', 'images')])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', 'stored-bytes'));
    }

    public function testAnUploadedFileIsSentAsTheImageContent(): void
    {
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        $path = __DIR__ . '/../../../Fixtures/Images/red.png';

        Image::of('Make it brighter')
            ->attachments([new UploadedFile($path, 'source.png', 'image/png', null, true)])
            ->generate(provider: 'openai', model: 'gpt-image-1');

        Http::assertSent(fn (Request $request): bool => $request->hasFile('image[]', file_get_contents($path)));
    }

    public function testAProviderImageCannotBeUsedForEdits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image attachment type');
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        Image::of('Make it brighter')
            ->attachments([new ProviderImage('file-123')])
            ->generate(provider: 'openai', model: 'gpt-image-1');
    }

    public function testADocumentAttachmentCannotBeUsedForEdits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image attachment type');
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        Image::of('Make it brighter')
            ->attachments([new LocalDocument('/tmp/source.pdf', 'application/pdf')])
            ->generate(provider: 'openai', model: 'gpt-image-1');
    }

    public function testNonGptImageModelsSendASingleImageField(): void
    {
        Http::fake(['*' => $this->fakeOpenAiImageEditResponse()]);

        Image::of('Make it brighter')
            ->attachments([new Base64Image(base64_encode('source-bytes'), 'image/png')])
            ->generate(provider: 'openai', model: 'dall-e-2');

        Http::assertSent(fn (Request $request): bool => $request->hasFile('image', 'source-bytes', 'image.png'));
    }
}
