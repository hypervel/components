<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Notification;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;

class NotificationTest extends TestCase
{
    public function testMayBeUsedInTools(): void
    {
        $notification = new Notification('booking/starting', ['step' => 1]);

        $payload = $notification->toTool(new class extends Tool {});

        $this->assertSame([
            'method' => 'booking/starting',
            'params' => ['step' => 1],
        ], $payload);
    }

    public function testMayBeUsedInPrompts(): void
    {
        $notification = new Notification('booking/completed', ['step' => 2]);

        $payload = $notification->toPrompt(new class extends Prompt {});

        $this->assertSame([
            'method' => 'booking/completed',
            'params' => ['step' => 2],
        ], $payload);
    }

    public function testMayBeUsedInResourcesWithoutAddingMetadata(): void
    {
        $notification = new Notification('status/update', ['ok' => true]);
        $resource = new class extends Resource {
            protected string $uri = 'file://ignored.txt';

            protected string $name = 'ignored';

            protected string $title = 'Ignored';

            protected string $mimeType = 'text/plain';
        };

        $payload = $notification->toResource($resource);

        $this->assertSame([
            'method' => 'status/update',
            'params' => ['ok' => true],
        ], $payload);
    }

    public function testCastsToStringAsMethod(): void
    {
        $notification = new Notification('booking/starting', []);

        $this->assertSame('booking/starting', (string) $notification);
    }

    public function testConvertsToArrayWithMethodAndParams(): void
    {
        $notification = new Notification('notify/me', ['x' => 1, 'y' => 2]);

        $this->assertSame([
            'method' => 'notify/me',
            'params' => ['x' => 1, 'y' => 2],
        ], $notification->toArray());
    }

    public function testSupportsMetaViaSetMeta(): void
    {
        $notification = new Notification('test/event', ['data' => 'value']);
        $notification->setMeta(['author' => 'system']);

        $this->assertSame([
            'method' => 'test/event',
            'params' => [
                'data' => 'value',
                '_meta' => ['author' => 'system'],
            ],
        ], $notification->toArray());
    }

    public function testSupportsMetaInParams(): void
    {
        $notification = new Notification('test/event', [
            'data' => 'value',
            '_meta' => ['source' => 'params'],
        ]);

        $this->assertSame([
            'method' => 'test/event',
            'params' => [
                'data' => 'value',
                '_meta' => ['source' => 'params'],
            ],
        ], $notification->toArray());
    }

    public function testDoesNotIncludeMetaIfNotSet(): void
    {
        $notification = new Notification('test/event', ['data' => 'value']);

        $this->assertArrayNotHasKey('_meta', $notification->toArray()['params']);
    }
}
