<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Audio;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;

class AudioTest extends TestCase
{
    public function testMayBeUsedInTools(): void
    {
        $audio = new Audio('raw-audio-bytes', 'audio/mp3');

        $payload = $audio->toTool(new class extends Tool {});

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('raw-audio-bytes'),
            'mimeType' => 'audio/mp3',
        ], $payload);
    }

    public function testMayBeUsedInPrompts(): void
    {
        $audio = new Audio('raw-audio-bytes', 'audio/mp3');

        $payload = $audio->toPrompt(new class extends Prompt {});

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('raw-audio-bytes'),
            'mimeType' => 'audio/mp3',
        ], $payload);
    }

    public function testMayBeUsedInResources(): void
    {
        $audio = new Audio('raw-audio-bytes', 'audio/mp3');

        $resource = new class extends Resource {
            protected string $uri = 'file://audio/clip.mp3';

            protected string $mimeType = 'audio/mp3';
        };

        $payload = $audio->toResource($resource);

        $this->assertSame([
            'blob' => base64_encode('raw-audio-bytes'),
            'uri' => 'file://audio/clip.mp3',
            'mimeType' => 'audio/mp3',
        ], $payload);
    }

    public function testCastsToStringAsRawData(): void
    {
        $audio = new Audio('hello');

        $this->assertSame('hello', (string) $audio);
    }

    public function testConvertsToArrayWithTypeDataAndMimeType(): void
    {
        $audio = new Audio('bytes', 'audio/ogg');

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('bytes'),
            'mimeType' => 'audio/ogg',
        ], $audio->toArray());
    }

    public function testDefaultsMimeTypeToAudioWav(): void
    {
        $audio = new Audio('data');

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('data'),
            'mimeType' => 'audio/wav',
        ], $audio->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $audio = new Audio('binary-data');
        $audio->setMeta(['duration' => '3.5s']);

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('binary-data'),
            'mimeType' => 'audio/wav',
            '_meta' => ['duration' => '3.5s'],
        ], $audio->toArray());
    }

    public function testDoesNotIncludeMetaIfNull(): void
    {
        $audio = new Audio('data');

        $array = $audio->toArray();

        $this->assertSame([
            'type' => 'audio',
            'data' => base64_encode('data'),
            'mimeType' => 'audio/wav',
        ], $array);
        $this->assertArrayNotHasKey('_meta', $array);
    }
}
