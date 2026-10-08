<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Video;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64VideoTest extends TestCase
{
    public function testBase64VideoRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 video content cannot be empty.');

        new Base64Video('');
    }
}
