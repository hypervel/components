<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Tests\TestCase;

class GeneratedImageTest extends TestCase
{
    public function testGeneratedImageStoresBase64EncodedImage(): void
    {
        $image = new GeneratedImage('SGVsbG8gV29ybGQ=', 'image/png');

        $this->assertSame('SGVsbG8gV29ybGQ=', $image->image);
        $this->assertSame('image/png', $image->mime);
    }

    public function testGeneratedImageAcceptsNullMimeType(): void
    {
        $image = new GeneratedImage('YmFzZTY0IGRhdGE=');

        $this->assertSame('YmFzZTY0IGRhdGE=', $image->image);
        $this->assertNull($image->mime);
    }

    public function testGeneratedImageContentDecodesBase64ToString(): void
    {
        $image = new GeneratedImage('SGVsbG8gV29ybGQ=');

        $this->assertSame('Hello World', $image->content());
    }

    public function testGeneratedImageToArrayIncludesImageAndMime(): void
    {
        $image = new GeneratedImage('dGVzdCBpbWFnZQ==', 'image/jpeg');

        $array = $image->toArray();

        $this->assertSame('dGVzdCBpbWFnZQ==', $array['image']);
        $this->assertSame('image/jpeg', $array['mime']);
    }

    public function testGeneratedImageJsonSerializeReturnsToArray(): void
    {
        $image = new GeneratedImage('ZGF0YQ==', 'image/webp');

        $json = $image->jsonSerialize();

        $this->assertSame('ZGF0YQ==', $json['image']);
        $this->assertSame('image/webp', $json['mime']);
    }

    public function testGeneratedImageToStringReturnsDecodedContent(): void
    {
        $image = new GeneratedImage('VGVzdA==');

        $this->assertSame('Test', (string) $image);
    }
}
