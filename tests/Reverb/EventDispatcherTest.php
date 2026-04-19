<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Reverb\Webhooks\Jobs\WebhookDeliveryJob;
use Hypervel\Support\Facades\Queue;
use Mockery as m;

class EventDispatcherTest extends ReverbTestCase
{
    public function testCanPublishAnEventWhenEnabled()
    {
        $app = app(ApplicationProvider::class)->findByKey('reverb-key');
        app(ServerProviderManager::class)->withPublishing();

        $pubSub = m::mock(PubSubProvider::class);
        $pubSub->shouldReceive('publish')->once()
            ->with(['type' => 'message', 'app_id' => $app->id(), 'payload' => ['channel' => 'test-channel']]);

        $this->app->instance(PubSubProvider::class, $pubSub);

        EventDispatcher::dispatch($app, ['channel' => 'test-channel']);
    }

    public function testCanBroadcastAnEventDirectlyWhenPublishingDisabled()
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

    public function testCanBroadcastAnEventForMultipleChannels()
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

    public function testBroadcastToCacheChannelClearsCacheMissLock()
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

    public function testCacheMissLockClearsOnVacateAndFiresOnRecreation()
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
