<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use ErrorException;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\Video;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Ai\TestCase;

class FileToArrayTest extends TestCase
{
    protected string $directory;

    /**
     * Set up an isolated directory for local attachments.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = ParallelTesting::tempDir('FileToArrayTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->directory);
        $files->makeDirectory($this->directory, recursive: true);
    }

    /**
     * Remove local attachments after the test.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function testStoredDocumentToArrayFallsBackToBasenameWithoutTouchingTheDisk(): void
    {
        Storage::fake('docs');

        $array = Document::fromStorage('invoices/invoice-2026-04.pdf', 'docs')->toArray();

        $this->assertSame([
            'type' => 'stored-document',
            'name' => 'invoice-2026-04.pdf',
            'path' => 'invoices/invoice-2026-04.pdf',
            'disk' => 'docs',
        ], $array);
    }

    public function testStoredAudioToArrayFallsBackToBasename(): void
    {
        Storage::fake('docs');

        $array = Audio::fromStorage('clips/hello.mp3', 'docs')->toArray();

        $this->assertSame('stored-audio', $array['type']);
        $this->assertSame('hello.mp3', $array['name']);
        $this->assertArrayNotHasKey('mime', $array);
    }

    public function testStoredImageToArrayFallsBackToBasename(): void
    {
        Storage::fake('docs');

        $array = Image::fromStorage('photos/avatar.png', 'docs')->toArray();

        $this->assertSame('stored-image', $array['type']);
        $this->assertSame('avatar.png', $array['name']);
        $this->assertArrayNotHasKey('mime', $array);
    }

    public function testStoredVideoToArrayFallsBackToBasename(): void
    {
        Storage::fake('docs');

        $array = Video::fromStorage('clips/demo.mp4', 'docs')->toArray();

        $this->assertSame('stored-video', $array['type']);
        $this->assertSame('demo.mp4', $array['name']);
        $this->assertArrayNotHasKey('mime', $array);
    }

    public function testLocalImageToArrayUsesBasenameAndTheRawMimeProperty(): void
    {
        $path = $this->directory . '/local-image';
        file_put_contents($path, 'data');

        $array = Image::fromPath($path)->toArray();

        $this->assertSame('local-image', $array['type']);
        $this->assertSame(basename($path), $array['name']);
        $this->assertSame($path, $array['path']);
        $this->assertNull($array['mime']);
    }

    public function testLocalImageToArrayReturnsTheExplicitlySetMimeType(): void
    {
        $path = $this->directory . '/local-image';
        file_put_contents($path, 'data');

        $array = Image::fromPath($path)->withMimeType('image/custom')->toArray();

        $this->assertSame('image/custom', $array['mime']);
    }

    public function testLocalVideoToArrayUsesBasenameAndTheRawMimeProperty(): void
    {
        $path = $this->directory . '/local-video';
        file_put_contents($path, 'data');

        $array = Video::fromPath($path)->toArray();

        $this->assertSame('local-video', $array['type']);
        $this->assertSame(basename($path), $array['name']);
        $this->assertSame($path, $array['path']);
        $this->assertNull($array['mime']);
    }

    public function testLocalVideoToArrayReturnsTheExplicitlySetMimeType(): void
    {
        $path = $this->directory . '/local-video';
        file_put_contents($path, 'data');

        $array = Video::fromPath($path)->withMimeType('video/custom')->toArray();

        $this->assertSame('video/custom', $array['mime']);
    }

    public function testBase64DocumentToArrayReflectsNameAndMime(): void
    {
        $document = Document::fromString('hello world', 'text/plain')->as('greeting.txt');

        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys([
            'type' => 'base64-document',
            'name' => 'greeting.txt',
            'mime' => 'text/plain',
        ], $document->toArray(), ['type', 'name', 'mime']);
    }

    public function testBase64VideoToArrayReflectsNameAndMime(): void
    {
        $video = Video::fromBase64(base64_encode('video'), 'video/mp4')->as('demo.mp4');

        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys([
            'type' => 'base64-video',
            'name' => 'demo.mp4',
            'mime' => 'video/mp4',
        ], $video->toArray(), ['type', 'name', 'mime']);
    }

    public function testRemoteDocumentToArrayNeverIssuesAnHttpRequest(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $array = Document::fromUrl('https://example.com/signed/private.pdf')->toArray();

        $this->assertSame([
            'type' => 'remote-document',
            'name' => 'private.pdf',
            'url' => 'https://example.com/signed/private.pdf',
            'mime' => null,
        ], $array);

        Http::assertNothingSent();
    }

    public function testRemoteImageToArrayReturnsTheExplicitlySetMimeTypeWithoutHttpCalls(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $array = Image::fromUrl('https://example.com/avatar.png')->withMimeType('image/png')->toArray();

        $this->assertSame('image/png', $array['mime']);
        $this->assertSame('avatar.png', $array['name']);

        Http::assertNothingSent();
    }

    public function testRemoteVideoToArrayReturnsTheExplicitlySetMimeTypeWithoutHttpCalls(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $array = Video::fromUrl('https://example.com/demo.mp4')->withMimeType('video/mp4')->toArray();

        $this->assertSame('video/mp4', $array['mime']);
        $this->assertSame('demo.mp4', $array['name']);

        Http::assertNothingSent();
    }

    public function testRemoteDocumentToArrayHandlesUrlsWithoutAPathComponent(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $array = Document::fromUrl('https://example.com')->toArray();
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $array['name']);
        $this->assertNull($array['mime']);

        Http::assertNothingSent();
    }

    public function testLocalImageToArrayDoesNotTouchTheFilesystem(): void
    {
        $path = $this->directory . '/missing.png';

        $array = Image::fromPath($path)->toArray();

        $this->assertSame([
            'type' => 'local-image',
            'name' => basename($path),
            'path' => $path,
            'mime' => null,
        ], $array);
    }
}
