<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses;

use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ImageResponseTest extends TestCase
{
    public function testMimeFallsBackToImagePngWhenNoMimeTypeIsSet(): void
    {
        $this->assertSame('image/png', (new GeneratedImage('aGVsbG8='))->mime());
        $this->assertSame('image/webp', (new GeneratedImage('aGVsbG8=', 'image/webp'))->mime());
    }

    public function testToHtmlRendersAnImgTagWithTheImageMimeType(): void
    {
        $response = new ImageResponse(
            new Collection([new GeneratedImage('aGVsbG8=', 'image/jpeg')]),
            new ImageUsage,
            new Meta('openai', 'gpt-image-1'),
        );

        $this->assertSame(
            '<img src="data:image/jpeg;base64,aGVsbG8=" alt="" />',
            $response->toHtml(),
        );
    }

    public function testToHtmlFallsBackToImagePngWhenTheImageHasNoMimeType(): void
    {
        $response = new ImageResponse(
            new Collection([new GeneratedImage('aGVsbG8=')]),
            new ImageUsage,
            new Meta('openai', 'gpt-image-1'),
        );

        $this->assertSame(
            '<img src="data:image/png;base64,aGVsbG8=" alt="" />',
            $response->toHtml(),
        );
    }

    public function testToHtmlEscapesTheAltAttribute(): void
    {
        $response = new ImageResponse(
            new Collection([new GeneratedImage('aGVsbG8=', 'image/png')]),
            new ImageUsage,
            new Meta('openai', 'gpt-image-1'),
        );

        $this->assertSame(
            '<img src="data:image/png;base64,aGVsbG8=" alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" />',
            $response->toHtml('"><script>alert(1)</script>'),
        );
    }

    public function testFirstImageThrowsWhenTheResponseContainsNoImages(): void
    {
        $response = new ImageResponse(
            new Collection,
            new ImageUsage,
            new Meta('openai', 'gpt-image-1'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The image response does not contain any images.');

        $response->firstImage();
    }
}
