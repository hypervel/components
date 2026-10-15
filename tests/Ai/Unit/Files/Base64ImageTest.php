<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Files;

use Hypervel\Ai\Files\Base64Image;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class Base64ImageTest extends TestCase
{
    public function testContentDecodesBinaryDataWithWhitespace(): void
    {
        $content = "\x00\xffimage";

        $this->assertSame($content, (new Base64Image(chunk_split(base64_encode($content), 4)))->content());
    }

    public function testContentRejectsInvalidBase64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base64 file content.');

        (new Base64Image('invalid!'))->content();
    }

    public function testBase64ImageRejectsEmptyContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base64 image content cannot be empty.');

        new Base64Image('');
    }
}
