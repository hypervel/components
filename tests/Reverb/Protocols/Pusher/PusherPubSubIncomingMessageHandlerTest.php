<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Protocols\Pusher\PusherPubSubIncomingMessageHandler;
use Hypervel\Tests\Reverb\ReverbTestCase;

class PusherPubSubIncomingMessageHandlerTest extends ReverbTestCase
{
    public function testListenerOnlyEventWithoutAppIdDoesNotThrow()
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

    public function testUnknownEventTypeIsSilentlyIgnored()
    {
        $handler = new PusherPubSubIncomingMessageHandler;

        // No listener registered, unknown type, no app_id — should not throw
        $handler->handle(json_encode([
            'type' => 'completely_unknown',
            'data' => [],
        ]));

        $this->assertTrue(true);
    }

    public function testMessageEventDispatchesToLocalConnections()
    {
        $handler = new PusherPubSubIncomingMessageHandler;

        // Message type requires app_id — verify it resolves the app
        // and dispatches synchronously (using the test app from ReverbTestCase)
        $handler->handle(json_encode([
            'type' => 'message',
            'app_id' => '123456',
            'payload' => [
                'channel' => 'nonexistent-channel',
                'event' => 'TestEvent',
                'data' => '{}',
            ],
        ]));

        // No exception — the channel doesn't exist locally so nothing broadcasts,
        // but the handler resolves the app and processes without error
        $this->assertTrue(true);
    }
}
