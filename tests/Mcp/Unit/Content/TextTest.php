<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Text;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;

class TextTest extends TestCase
{
    public function testEncodesContentToResourcePayloadWithMetadata(): void
    {
        $text = new Text('Hello world');
        $resource = new class extends Resource {
            protected string $uri = 'file://readme.txt';

            protected string $name = 'readme';

            protected string $title = 'Readme File';

            protected string $mimeType = 'text/plain';
        };

        $payload = $text->toResource($resource);

        $this->assertSame([
            'text' => 'Hello world',
            'uri' => 'file://readme.txt',
            'mimeType' => 'text/plain',
        ], $payload);
    }

    public function testPreservesMetaWhenConvertingToAResourcePayload(): void
    {
        $text = new Text('Hello world');
        $text->setMeta(['author' => 'John']);

        $resource = new class extends Resource {
            protected string $uri = 'file://readme.txt';

            protected string $name = 'readme';

            protected string $title = 'Readme File';

            protected string $mimeType = 'text/plain';
        };

        $payload = $text->toResource($resource);

        $this->assertSame([
            'text' => 'Hello world',
            'uri' => 'file://readme.txt',
            'mimeType' => 'text/plain',
            '_meta' => ['author' => 'John'],
        ], $payload);
    }

    public function testMayBeUsedInTools(): void
    {
        $text = new Text('Run me');

        $payload = $text->toTool(new class extends Tool {});

        $this->assertSame([
            'type' => 'text',
            'text' => 'Run me',
        ], $payload);
    }

    public function testMayBeUsedInPrompts(): void
    {
        $text = new Text('Say hi');

        $payload = $text->toPrompt(new class extends Prompt {});

        $this->assertSame([
            'type' => 'text',
            'text' => 'Say hi',
        ], $payload);
    }

    public function testCastsToStringAsRawText(): void
    {
        $text = new Text('plain');

        $this->assertSame('plain', (string) $text);
    }

    public function testConvertsToArrayWithTypeAndText(): void
    {
        $text = new Text('abc');

        $this->assertSame([
            'type' => 'text',
            'text' => 'abc',
        ], $text->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $text = new Text('Hello');
        $text->setMeta(['author' => 'John']);

        $this->assertSame([
            'type' => 'text',
            'text' => 'Hello',
            '_meta' => ['author' => 'John'],
        ], $text->toArray());
    }

    public function testDoesNotIncludeMetaIfNull(): void
    {
        $text = new Text('Hello');

        $this->assertSame([
            'type' => 'text',
            'text' => 'Hello',
        ], $text->toArray());
    }
}
