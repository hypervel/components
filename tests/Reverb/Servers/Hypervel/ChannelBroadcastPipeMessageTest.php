<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Servers\Hypervel;

use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Reverb\Protocols\Pusher\MetricsHandler;
use Hypervel\Reverb\Protocols\Pusher\PendingMetric;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Servers\Hypervel\MetricsRequestPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\MetricsResponsePipeMessage;
use Hypervel\Reverb\Servers\Hypervel\TerminateUserPipeMessage;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Server;

class ChannelBroadcastPipeMessageTest extends ReverbTestCase
{
    public function testPipeMessageBroadcastsToLocalConnections(): void
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

    public function testPipeMessageExcludesExceptSocketId(): void
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

    public function testPipeMessageIgnoresChannelsNotOnThisWorker(): void
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

    public function testPipeMessageIgnoresNonChannelBroadcastMessages(): void
    {
        $connection = $this->subscribeConnection('test-channel');

        // Send a non-ChannelBroadcastPipeMessage — the listener should ignore it
        $server = m::mock(Server::class);

        event(new OnPipeMessage($server, 0, 'some-other-data'));

        $connection->assertNothingReceived();
    }

    public function testPipeMessageBroadcastsToMultipleChannels(): void
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

    public function testPipeMessageSendsCorrectChannelNamePerChannel(): void
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

    public function testPipeMessageClearsCacheMissLockForCacheChannel(): void
    {
        $this->subscribeConnection('cache-test-channel');

        $sharedState = $this->app->make(SharedState::class);

        // Clear the lock that the subscribe acquired, then re-acquire
        $sharedState->clearCacheMissLock('123456', 'cache-test-channel');
        $this->assertTrue($sharedState->tryCacheMissLock('123456', 'cache-test-channel'));

        // Pipe message broadcast to the cache channel should clear the lock
        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['cache-test-channel'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}', 'channel' => 'cache-test-channel'],
            exceptSocketId: null,
        );

        event(new OnPipeMessage(m::mock(Server::class), 0, $message));

        // Lock should be cleared — re-acquire should succeed
        $this->assertTrue($sharedState->tryCacheMissLock('123456', 'cache-test-channel'));
    }

    public function testPipeMessageAttemptsEveryChannelBeforePropagatingTheFirstFailure(): void
    {
        $cacheConnection = $this->subscribeConnection('cache-first');
        $laterConnection = $this->subscribeConnection('later');
        $cacheConnection->resetReceived();
        $laterConnection->resetReceived();
        $failure = new RuntimeException('cache lock failed');
        $sharedState = m::mock(SharedState::class);
        $sharedState->expects('clearCacheMissLock')
            ->with('123456', 'cache-first')
            ->andThrow($failure);
        $this->app->instance(SharedState::class, $sharedState);
        $message = new ChannelBroadcastPipeMessage(
            appId: '123456',
            channels: ['cache-first', 'later'],
            payload: ['event' => 'NewEvent', 'data' => '{"some":"data"}'],
            exceptSocketId: null,
        );

        try {
            event(new OnPipeMessage(m::mock(Server::class), 0, $message));
            $this->fail('Expected the first pipe delivery failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $cacheConnection->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'cache-first',
        ]);
        $laterConnection->assertReceived([
            'event' => 'NewEvent',
            'data' => '{"some":"data"}',
            'channel' => 'later',
        ]);
    }

    public function testMetricsRequestReturnsTheLocalWorkerSlice(): void
    {
        $metrics = m::mock(MetricsHandler::class);
        $metrics->expects('get')
            ->with(m::on(fn (PendingMetric $metric): bool => $metric->key() === 'request'
                && $metric->application()->id() === '123456'
                && $metric->type()->value === 'connections'))
            ->andReturn(['count' => 1]);
        $this->app->instance(MetricsHandler::class, $metrics);
        $server = m::mock(Server::class);
        $server->expects('sendMessage')
            ->with(m::on(fn (MetricsResponsePipeMessage $message): bool => $message->requestId === 'request'
                && $message->payload === ['count' => 1]), 2)
            ->andReturnTrue();

        event(new OnPipeMessage($server, 2, new MetricsRequestPipeMessage(
            'request',
            '123456',
            'connections',
            [],
        )));
    }

    public function testMetricsResponseIsDeliveredToThePendingGather(): void
    {
        $message = new MetricsResponsePipeMessage('request', ['count' => 1]);
        $metrics = m::mock(MetricsHandler::class);
        $metrics->expects('receive')->with($message);
        $this->app->instance(MetricsHandler::class, $metrics);

        event(new OnPipeMessage(m::mock(Server::class), 2, $message));
    }

    public function testTerminateUserMessageDisconnectsMatchingLocalConnections(): void
    {
        $matching = $this->subscribeConnection('presence-test', [
            'user_id' => 'matching',
            'user_info' => ['name' => 'Taylor'],
        ]);
        $other = $this->subscribeConnection('presence-test', [
            'user_id' => 'other',
            'user_info' => ['name' => 'Abigail'],
        ]);

        event(new OnPipeMessage(
            m::mock(Server::class),
            2,
            new TerminateUserPipeMessage('123456', 'matching'),
        ));

        $matching->assertHasBeenTerminated();
        $this->assertFalse($other->wasTerminated);
    }
}
