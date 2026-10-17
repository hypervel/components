<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Content;

use Hypervel\Mcp\Server\Content\Notification;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class NotificationTest extends TestCase
{
    public function testMayNotBeUsedAsToolResultContent(): void
    {
        $notification = new Notification('booking/starting', ['step' => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('yielded separately');
        $notification->toTool(new class extends Tool {});
    }

    public function testMayNotBeUsedAsPromptResultContent(): void
    {
        $notification = new Notification('booking/completed', ['step' => 2]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('yielded separately');
        $notification->toPrompt(new class extends Prompt {});
    }

    public function testMayNotBeUsedAsResourceResultContent(): void
    {
        $notification = new Notification('status/update', ['ok' => true]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('yielded separately');
        $notification->toResource(new class extends Resource {});
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

    public function testMergesSetMetaWithMetaInParams(): void
    {
        $notification = new Notification('test/event', [
            'data' => 'value',
            '_meta' => ['source' => 'params'],
        ]);
        $notification->setMeta(['source' => 'default', 'author' => 'system']);

        $this->assertSame([
            'method' => 'test/event',
            'params' => [
                'data' => 'value',
                '_meta' => ['source' => 'params', 'author' => 'system'],
            ],
        ], $notification->toArray());
    }

    public function testDoesNotIncludeMetaIfNotSet(): void
    {
        $notification = new Notification('test/event', ['data' => 'value']);

        $this->assertArrayNotHasKey('_meta', $notification->toArray()['params']);
    }
}
