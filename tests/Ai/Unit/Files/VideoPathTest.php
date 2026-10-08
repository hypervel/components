<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\LocalVideo;
use Hypervel\Ai\Files\StoredVideo;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class VideoPathTest extends TestCase
{
    public function testLocalVideoRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Video file path cannot be empty.');

        new LocalVideo('');
    }

    public function testLocalVideoRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Video file path cannot be empty.');

        new LocalVideo("  \t\n");
    }

    public function testStoredVideoRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Video file path cannot be empty.');

        new StoredVideo('');
    }

    public function testStoredVideoRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Video file path cannot be empty.');

        new StoredVideo("  \t\n");
    }
}
