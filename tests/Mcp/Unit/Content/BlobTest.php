<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Blob;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class BlobTest extends TestCase
{
    public function testEncodesContentToResourcePayloadWithMetadata(): void
    {
        $blob = new Blob('raw-bytes');
        $resource = new class extends Resource {
            protected string $uri = 'file://avatar.png';

            protected string $name = 'avatar';

            protected string $title = 'User Avatar';

            protected string $mimeType = 'image/png';
        };

        $payload = $blob->toResource($resource);

        $this->assertSame([
            'blob' => base64_encode('raw-bytes'),
            'uri' => 'file://avatar.png',
            'mimeType' => 'image/png',
        ], $payload);
    }

    public function testPreservesMetaWhenConvertingToAResourcePayload(): void
    {
        $blob = new Blob('raw-bytes');
        $blob->setMeta(['encoding' => 'base64']);

        $resource = new class extends Resource {
            protected string $uri = 'file://avatar.png';

            protected string $name = 'avatar';

            protected string $title = 'User Avatar';

            protected string $mimeType = 'image/png';
        };

        $payload = $blob->toResource($resource);

        $this->assertSame([
            'blob' => base64_encode('raw-bytes'),
            'uri' => 'file://avatar.png',
            'mimeType' => 'image/png',
            '_meta' => ['encoding' => 'base64'],
        ], $payload);
    }

    public function testThrowsWhenUsedInTools(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Blob content may not be used in tools.');
        $blob = new Blob('anything');

        $blob->toTool(new class extends Tool {});
    }

    public function testThrowsWhenUsedInPrompts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Blob content may not be used in prompts.');
        $blob = new Blob('anything');

        $blob->toPrompt(new class extends Prompt {});
    }

    public function testCastsToStringAsRawContent(): void
    {
        $blob = new Blob('hello');

        $this->assertSame('hello', (string) $blob);
    }

    public function testConvertsToArrayWithTypeAndRawBlob(): void
    {
        $blob = new Blob('bytes');

        $this->assertSame([
            'type' => 'blob',
            'blob' => 'bytes',
        ], $blob->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $blob = new Blob('binary-data');
        $blob->setMeta(['encoding' => 'base64']);

        $this->assertSame([
            'type' => 'blob',
            'blob' => 'binary-data',
            '_meta' => ['encoding' => 'base64'],
        ], $blob->toArray());
    }

    public function testDoesNotIncludeMetaIfNull(): void
    {
        $blob = new Blob('data');

        $this->assertSame([
            'type' => 'blob',
            'blob' => 'data',
        ], $blob->toArray());
    }
}
