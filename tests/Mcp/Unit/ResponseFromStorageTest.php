<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit;

use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Content\Audio;
use Hypervel\Mcp\Server\Content\Image;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

class ResponseFromStorageTest extends TestCase
{
    public function testCreatesImageResponseFromStorage(): void
    {
        Storage::fake();
        Storage::put('photos/avatar.png', 'raw-image-data');

        $response = Response::fromStorage('photos/avatar.png');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertSame('raw-image-data', (string) $response->content());
    }

    public function testCreatesImageResponseFromSpecificDisk(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('photos/avatar.png', 'raw-s3-data');

        $response = Response::fromStorage('photos/avatar.png', disk: 's3');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertSame('raw-s3-data', (string) $response->content());
    }

    public function testAutoDetectsMimeTypeForImages(): void
    {
        Storage::fake();
        Storage::put('photos/avatar.jpg', 'raw-jpeg-data');

        $response = Response::fromStorage('photos/avatar.jpg');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertSame(Storage::mimeType('photos/avatar.jpg'), $response->content()->toArray()['mimeType']);
    }

    public function testAllowsExplicitMimeTypeOverride(): void
    {
        Storage::fake();
        Storage::put('photos/avatar.png', 'raw-image-data');

        $response = Response::fromStorage('photos/avatar.png', mimeType: 'image/webp');

        $this->assertInstanceOf(Image::class, $response->content());
        $this->assertSame('image/webp', $response->content()->toArray()['mimeType']);
    }

    public function testCreatesAudioResponseFromStorage(): void
    {
        Storage::fake();
        Storage::put('recordings/clip.wav', 'raw-audio-data');

        $response = Response::fromStorage('recordings/clip.wav');

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertSame('raw-audio-data', (string) $response->content());
    }

    public function testCreatesAudioResponseFromSpecificDisk(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('recordings/clip.wav', 'raw-s3-audio');

        $response = Response::fromStorage('recordings/clip.wav', disk: 's3');

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertSame('raw-s3-audio', (string) $response->content());
    }

    public function testAutoDetectsMimeTypeForAudio(): void
    {
        Storage::fake();
        Storage::put('recordings/clip.mp3', 'raw-mp3-data');

        $response = Response::fromStorage('recordings/clip.mp3');

        $this->assertInstanceOf(Audio::class, $response->content());
        $this->assertSame(Storage::mimeType('recordings/clip.mp3'), $response->content()->toArray()['mimeType']);
    }

    public function testThrowsForUnsupportedMimeTypes(): void
    {
        Storage::fake();
        Storage::put('docs/report.pdf', 'raw-pdf-data');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported MIME type [application/pdf] for [docs/report.pdf].');
        Response::fromStorage('docs/report.pdf');
    }

    public function testThrowsWhenFileDoesNotExist(): void
    {
        Storage::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found at path [nonexistent/file.png].');
        Response::fromStorage('nonexistent/file.png');
    }

    public function testThrowsWhenStorageDiskThrowsOnMissingFile(): void
    {
        Storage::fake(config: ['throw' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found at path [missing/file.png].');
        Response::fromStorage('missing/file.png');
    }

    public function testThrowsWhenMimeTypeCannotBeDetermined(): void
    {
        Storage::fake();
        Storage::put('data/unknown', 'some-data');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine MIME type for [data/unknown].');
        Response::fromStorage('data/unknown');
    }
}
