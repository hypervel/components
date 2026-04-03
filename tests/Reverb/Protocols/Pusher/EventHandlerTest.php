<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JsonException;

/**
 * @internal
 * @coversNothing
 */
class EventHandlerTest extends ReverbTestCase
{
    protected FakeConnection $connection;

    protected EventHandler $pusher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new FakeConnection();
        $this->pusher = new EventHandler($this->app->make(ChannelManager::class));
    }

    public function testCanSendAnAcknowledgement()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:connection_established'
        );

        $this->connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $this->connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);
    }

    public function testCanSubscribeToAChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:subscribe',
            ['channel' => 'test-channel']
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'test-channel',
        ]);
    }

    public function testCanSubscribeToAnEmptyChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:subscribe',
            ['channel' => '']
        );

        $this->connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
        ]);
    }

    public function testCanUnsubscribeFromAChannel()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:unsubscribe',
            ['channel' => 'test-channel']
        );

        $this->connection->assertNothingReceived();
    }

    public function testCanRespondToAPing()
    {
        $this->pusher->handle(
            $this->connection,
            'pusher:ping',
        );

        $this->connection->assertReceived([
            'event' => 'pusher:pong',
        ]);
    }

    public function testCanCorrectlyFormatAPayload()
    {
        $payload = $this->pusher->formatPayload(
            'foo',
            ['bar' => 'baz'],
            'test-channel',
        );

        $this->assertSame(json_encode([
            'event' => 'pusher:foo',
            'data' => json_encode(['bar' => 'baz']),
            'channel' => 'test-channel',
        ]), $payload);

        $payload = $this->pusher->formatPayload('foo');

        $this->assertSame(json_encode([
            'event' => 'pusher:foo',
        ]), $payload);
    }

    public function testCanCorrectlyFormatAnInternalPayload()
    {
        $payload = $this->pusher->formatInternalPayload(
            'foo',
            ['bar' => 'baz'],
            'test-channel',
        );

        $this->assertSame(json_encode([
            'event' => 'pusher_internal:foo',
            'data' => json_encode(['bar' => 'baz']),
            'channel' => 'test-channel',
        ]), $payload);

        $payload = $this->pusher->formatInternalPayload('foo');

        $this->assertSame(json_encode([
            'event' => 'pusher_internal:foo',
            'data' => '{}',
        ]), $payload);
    }

    public function testFormatPayloadReturnsString()
    {
        $payload = $this->pusher->formatPayload('foo', ['bar' => 'baz']);

        $this->assertIsString($payload);
    }

    public function testFormatPayloadThrowsOnUnencodableData()
    {
        $this->expectException(JsonException::class);

        // NAN is not representable in JSON
        $this->pusher->formatPayload('foo', ['value' => NAN]);
    }
}
