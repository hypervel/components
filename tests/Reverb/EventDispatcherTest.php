<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Hypervel\Reverb\Protocols\Pusher\EventDispatcher;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
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
}
