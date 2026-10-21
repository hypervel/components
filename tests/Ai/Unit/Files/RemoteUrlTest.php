<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\RemoteAudio;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class RemoteUrlTest extends TestCase
{
    public function testRemoteDocumentRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote document URL cannot be empty.');

        new RemoteDocument('');
    }

    public function testRemoteDocumentRejectsWhitespaceOnlyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote document URL cannot be empty.');

        new RemoteDocument("  \t\n");
    }

    public function testRemoteImageRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote image URL cannot be empty.');

        new RemoteImage('');
    }

    public function testRemoteImageRejectsWhitespaceOnlyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote image URL cannot be empty.');

        new RemoteImage("  \t\n");
    }

    public function testRemoteAudioRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote audio URL cannot be empty.');

        new RemoteAudio('');
    }

    public function testRemoteAudioRejectsWhitespaceOnlyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote audio URL cannot be empty.');

        new RemoteAudio("  \t\n");
    }

    public function testRemoteVideoRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote video URL cannot be empty.');

        new RemoteVideo('');
    }

    public function testRemoteVideoRejectsWhitespaceOnlyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote video URL cannot be empty.');

        new RemoteVideo("  \t\n");
    }
}
