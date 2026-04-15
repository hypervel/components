<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use Swoole\Server;

class ChannelBroadcastPipeMessageTest extends ReverbTestCase
{
    public function testPipeMessageBroadcastsToLocalConnections()
    {
        $connectionOne = $this->subscribeConnection('test-channel');
        $connectionTwo = $this->subscribeConnection('test-channel');

        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['test-channel'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}', 'channel' => 'test-channel'],
            exceptSocketId: null,
        );

        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, $message));

        $connectionOne->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'test-channel',
        ]);
        $connectionTwo->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'test-channel',
        ]);
    }

    public function testPipeMessageExcludesExceptSocketId()
    {
        $connectionOne = $this->subscribeConnection('test-channel');
        $connectionTwo = $this->subscribeConnection('test-channel');

        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['test-channel'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}', 'channel' => 'test-channel'],
            exceptSocketId: $connectionOne->id(),
        );

        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, $message));

        $connectionOne->assertNothingReceived();
        $connectionTwo->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'test-channel',
        ]);
    }

    public function testPipeMessageIgnoresChannelsNotOnThisWorker()
    {
        $connection = $this->subscribeConnection('test-channel');

        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['nonexistent-channel'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}', 'channel' => 'nonexistent-channel'],
            exceptSocketId: null,
        );

        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, $message));

        // Connection on test-channel should not have received anything
        $connection->assertNothingReceived();
    }

    public function testPipeMessageIgnoresNonChannelBroadcastMessages()
    {
        $connection = $this->subscribeConnection('test-channel');

        // Send a non-ChannelBroadcastPipeMessage — the listener should ignore it
        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, 'some-other-data'));

        $connection->assertNothingReceived();
    }

    public function testPipeMessageBroadcastsToMultipleChannels()
    {
        $connectionOne = $this->subscribeConnection('channel-one');
        $connectionTwo = $this->subscribeConnection('channel-two');

        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['channel-one', 'channel-two'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}'],
            exceptSocketId: null,
        );

        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, $message));

        $connectionOne->assertReceivedCount(1);
        $connectionTwo->assertReceivedCount(1);
    }

    public function testPipeMessageSendsCorrectChannelNamePerChannel()
    {
        $connectionOne = $this->subscribeConnection('channel-one');
        $connectionTwo = $this->subscribeConnection('channel-two');

        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['channel-one', 'channel-two'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}'],
            exceptSocketId: null,
        );

        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, $message));

        // Each connection must receive the payload with ITS channel name,
        // not the last channel's name from the iteration.
        $connectionOne->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'channel-one',
        ]);
        $connectionTwo->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'channel-two',
        ]);
    }
}
