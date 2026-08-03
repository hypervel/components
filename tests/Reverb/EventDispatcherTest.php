<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ScopedChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\ChannelBroadcastPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Mockery as m;
use RuntimeException;
use Swoole\Server;

class EventDispatcherTest extends ReverbTestCase
{
    public function testCanPublishAnEventWhenEnabled(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        app(ServerProviderManager::class)->withPublishing();

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('publish')->once()
            ->with(['type' => 'message', 'app_id' => $app->id(), 'payload' => ['channel' => 'test-channel']]);

        $this->app->instance(PubSubProvider::class, $pubSub);

        EventDispatcher::dispatch($app, ['channel' => 'test-channel']);
    }

    public function testPublishedEventRetainsARemoteSocketId(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        app(ServerProviderManager::class)->withPublishing();
        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->expects('publish')->with([
            'type' => 'message',
            'app_id' => $app->id(),
            'payload' => ['channel' => 'test-channel'],
            'socket_id' => 'remote-socket',
        ]);
        $this->app->instance(PubSubProvider::class, $pubSub);

        EventDispatcher::dispatch(
            $app,
            ['channel' => 'test-channel'],
            socketId: 'remote-socket',
        );
    }

    public function testPipeFanOutRetainsARemoteSocketId(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 2];
        $server->worker_id = 0;
        $server->expects('sendMessage')
            ->with(
                m::on(fn (ChannelBroadcastPipeMessage $message): bool => $message->exceptSocketId === 'remote-socket'),
                1,
            )
            ->andReturnTrue();
        $this->app->instance(Server::class, $server);

        EventDispatcher::dispatch(
            $app,
            ['channel' => 'test-channel'],
            socketId: 'remote-socket',
        );
    }

    public function testPipeFanOutAttemptsEveryWorkerAndPreservesTheFirstFailure(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 4];
        $server->worker_id = 1;
        $workerIds = [];
        $server->expects('sendMessage')
            ->times(3)
            ->andReturnUsing(function (ChannelBroadcastPipeMessage $message, int $workerId) use (&$workerIds): bool {
                $workerIds[] = $workerId;

                return match ($workerId) {
                    0 => false,
                    2 => throw new RuntimeException('Later pipe failure.'),
                    default => true,
                };
            });
        $this->app->instance(Server::class, $server);

        try {
            EventDispatcher::dispatch($app, ['channel' => 'test-channel']);
            $this->fail('Expected pipe fan-out to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to broadcast a Reverb event to worker [0].', $exception->getMessage());
        }

        $this->assertSame([0, 2, 3], $workerIds);
    }

    public function testCanBroadcastAnEventDirectlyWhenPublishingDisabled(): void
    {
        $channelConnectionManager = m::mock(ChannelConnectionManager::class);
        $channelConnectionManager->shouldReceive('for')
            ->andReturn($channelConnectionManager);
        $channelConnectionManager->shouldReceive('all')->once()
            ->andReturn([]);

        $this->app->bind(ChannelConnectionManager::class, fn () => $channelConnectionManager);

        $this->channels()->findOrCreate('test-channel');

        EventDispatcher::dispatch(app(ApplicationProvider::class)->findByKey('reverb-key'), ['channel' => 'test-channel']);
    }

    public function testCanBroadcastAnEventForMultipleChannels(): void
    {
        $channelConnectionManager = m::mock(ChannelConnectionManager::class);
        $channelConnectionManager->shouldReceive('for')
            ->andReturn($channelConnectionManager);
        $channelConnectionManager->shouldReceive('all')->twice()
            ->andReturn([]);

        $this->app->bind(ChannelConnectionManager::class, fn () => $channelConnectionManager);

        $this->channels()->findOrCreate('test-channel-one');
        $this->channels()->findOrCreate('test-channel-two');

        EventDispatcher::dispatch(app(ApplicationProvider::class)->findByKey('reverb-key'), ['channels' => ['test-channel-one', 'test-channel-two']]);
    }

    public function testSynchronousDispatchAttemptsEveryChannelAndFanOutBeforeThrowing(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        $failure = new RuntimeException('cache lock failed');
        $cacheChannel = m::mock(CacheChannel::class);
        $cacheChannel->allows('name')->andReturn('cache-first');
        $cacheChannel->expects('broadcast')->once();
        $laterChannel = m::mock(Channel::class);
        $laterChannel->allows('name')->andReturn('later');
        $laterChannel->expects('broadcast')->once();
        $channels = m::mock(ScopedChannelManager::class);
        $channels->expects('find')->with('cache-first')->andReturn($cacheChannel);
        $channels->expects('find')->with('later')->andReturn($laterChannel);
        $manager = m::mock(ChannelManager::class);
        $manager->expects('for')->twice()->with($app)->andReturn($channels);
        $this->app->instance(ChannelManager::class, $manager);
        $sharedState = m::mock(SharedState::class);
        $sharedState->expects('clearCacheMissLock')
            ->with($app->id(), 'cache-first')
            ->andThrow($failure);
        $this->app->instance(SharedState::class, $sharedState);
        $server = m::mock(Server::class);
        $server->setting = ['worker_num' => 2];
        $server->worker_id = 0;
        $server->expects('sendMessage')->once()->andReturnTrue();
        $this->app->instance(Server::class, $server);

        try {
            EventDispatcher::dispatchSynchronously($app, [
                'event' => 'test-event',
                'data' => 'payload',
                'channels' => ['cache-first', 'later'],
            ]);
            $this->fail('Expected the first population failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testBroadcastToCacheChannelClearsCacheMissLock(): void
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        $sharedState = app(SharedState::class);

        // Subscribe to create the cache channel (this acquires the cache_miss
        // lock as a side effect of sendCachedPayload on the empty channel)
        $this->subscribeConnection('cache-test-channel');

        // Clear the lock from the subscribe, then re-acquire for the test
        $sharedState->clearCacheMissLock($app->id(), 'cache-test-channel');
        $this->assertTrue($sharedState->tryCacheMissLock($app->id(), 'cache-test-channel'));

        // Broadcast — should clear the lock
        EventDispatcher::dispatchSynchronously($app, [
            'event' => 'test-event',
            'data' => 'payload',
            'channel' => 'cache-test-channel',
        ]);

        // Lock should be cleared — re-acquire should succeed
        $this->assertTrue($sharedState->tryCacheMissLock($app->id(), 'cache-test-channel'));
    }

    public function testCacheMissLockClearsOnVacateAndFiresOnRecreation(): void
    {
        Queue::fake();

        $this->app['config']->set('reverb.apps.apps.0.webhooks', [
            'url' => 'https://example.com/webhook',
            'events' => ['cache_miss'],
            'disconnect_smoothing_ms' => 0,
        ]);

        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        $channels = app(ChannelManager::class)->for($app);

        // Subscribe to empty cache channel — fires cache_miss webhook
        $connection = $this->subscribeConnection('cache-test-channel');

        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        });

        // Unsubscribe — channel vacated, cache_miss lock cleared
        $channels->find('cache-test-channel')->unsubscribe($connection);

        // Reset queue
        Queue::fake();

        // Re-subscribe to the same still-empty cache channel
        $connection2 = $this->subscribeConnection('cache-test-channel');

        // Should fire a new cache_miss webhook (lock was cleared on vacate)
        Queue::assertPushed(WebhookDeliveryJob::class, function (WebhookDeliveryJob $job) {
            return $job->payload->events[0]['name'] === 'cache_miss';
        });
    }
}
