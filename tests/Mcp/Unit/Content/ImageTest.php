<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Image;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;

class ImageTest extends TestCase
{
    public function testMayBeUsedInTools(): void
    {
        $image = new Image('raw-image-bytes', 'image/jpeg');

        $payload = $image->toTool(new class extends Tool {});

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('raw-image-bytes'),
            'mimeType' => 'image/jpeg',
        ], $payload);
    }

    public function testMayBeUsedInPrompts(): void
    {
        $image = new Image('raw-image-bytes', 'image/jpeg');

        $payload = $image->toPrompt(new class extends Prompt {});

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('raw-image-bytes'),
            'mimeType' => 'image/jpeg',
        ], $payload);
    }

    public function testMayBeUsedInResources(): void
    {
        $image = new Image('raw-image-bytes', 'image/jpeg');

        $resource = new class extends Resource {
            protected string $uri = 'file://images/photo.jpg';

            protected string $mimeType = 'image/jpeg';
        };

        $payload = $image->toResource($resource);

        $this->assertSame([
            'blob' => base64_encode('raw-image-bytes'),
            'uri' => 'file://images/photo.jpg',
            'mimeType' => 'image/jpeg',
        ], $payload);
    }

    public function testCastsToStringAsRawData(): void
    {
        $image = new Image('hello');

        $this->assertSame('hello', (string) $image);
    }

    public function testConvertsToArrayWithTypeDataAndMimeType(): void
    {
        $image = new Image('bytes', 'image/webp');

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('bytes'),
            'mimeType' => 'image/webp',
        ], $image->toArray());
    }

    public function testDefaultsMimeTypeToImagePng(): void
    {
        $image = new Image('data');

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('data'),
            'mimeType' => 'image/png',
        ], $image->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $image = new Image('binary-data');
        $image->setMeta(['source' => 'camera']);

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('binary-data'),
            'mimeType' => 'image/png',
            '_meta' => ['source' => 'camera'],
        ], $image->toArray());
    }

    public function testDoesNotIncludeMetaIfNull(): void
    {
        $image = new Image('data');

        $array = $image->toArray();

        $this->assertSame([
            'type' => 'image',
            'data' => base64_encode('data'),
            'mimeType' => 'image/png',
        ], $array);
        $this->assertArrayNotHasKey('_meta', $array);
    }
}
