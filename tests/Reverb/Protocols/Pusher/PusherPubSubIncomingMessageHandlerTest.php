<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Protocols\Pusher\PusherPubSubIncomingMessageHandler;
use Hypervel\Tests\Reverb\ReverbTestCase;

class PusherPubSubIncomingMessageHandlerTest extends ReverbTestCase
{
    public function testListenerOnlyEventWithoutAppIdDoesNotThrow(): void
    {
        $handler = new PusherPubSubIncomingMessageHandler;

        $listenerCalled = false;
        $handler->listen('some_random_key', function (array $event) use (&$listenerCalled) {
            $listenerCalled = true;
        });

        // Metric response events have type = random key and no app_id.
        // The handler must call the listener and NOT throw when app_id is missing.
        $handler->handle(json_encode([
            'type' => 'some_random_key',
            'payload' => ['some' => 'data'],
        ]));

        $this->assertTrue($listenerCalled);
    }

    public function testUnknownEventTypeIsSilentlyIgnored(): void
    {
        $handler = new PusherPubSubIncomingMessageHandler;

        $connection = $this->subscribeConnection('test-channel');

        $handler->handle(json_encode([
            'type' => 'completely_unknown',
            'data' => [],
        ]));

        $connection->assertNothingReceived();
    }

    public function testMessageEventDispatchesToLocalConnections(): void
    {
        $connection = $this->subscribeConnection('test-channel');
        $handler = new PusherPubSubIncomingMessageHandler;
        $payload = ['channel' => 'test-channel', 'event' => 'TestEvent', 'data' => '{}'];

        $handler->handle(json_encode([
            'type' => 'message',
            'app_id' => '123456',
            'payload' => [...$payload, 'channel' => 'nonexistent-channel'],
        ]));

        $connection->assertNothingReceived();

        $handler->handle(json_encode([
            'type' => 'message',
            'app_id' => '123456',
            'payload' => $payload,
        ]));

        $connection->assertReceived($payload);
    }

    public function testMessageEventExcludesTheSocketOnTheWorkerThatOwnsIt(): void
    {
        $connection = $this->subscribeConnection('test-channel');
        $handler = new PusherPubSubIncomingMessageHandler;

        $handler->handle(json_encode([
            'type' => 'message',
            'app_id' => '123456',
            'socket_id' => $connection->id(),
            'payload' => [
                'channel' => 'test-channel',
                'event' => 'TestEvent',
                'data' => '{}',
            ],
        ]));

        $connection->assertNothingReceived();
    }
}
