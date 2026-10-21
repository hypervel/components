<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ImagePathTest extends TestCase
{
    public function testLocalImageRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file path cannot be empty.');

        new LocalImage('');
    }

    public function testLocalImageRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file path cannot be empty.');

        new LocalImage("  \t\n");
    }

    public function testStoredImageRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file path cannot be empty.');

        new StoredImage('');
    }

    public function testStoredImageRejectsWhitespaceOnlyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file path cannot be empty.');

        new StoredImage("  \t\n");
    }
}
