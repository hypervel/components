<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\CacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;
use Hypervel\Reverb\Protocols\Pusher\Channels\ChannelBroker;
use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceCacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\PresenceChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateCacheChannel;
use Hypervel\Reverb\Protocols\Pusher\Channels\PrivateChannel;
use Hypervel\Tests\Reverb\ReverbTestCase;

class ChannelBrokerTest extends ReverbTestCase
{
    public function testCanReturnAChannelInstance()
    {
        $this->assertInstanceOf(Channel::class, ChannelBroker::create('foo'));
    }

    public function testCanReturnAPrivateChannelInstance()
    {
        $this->assertInstanceOf(PrivateChannel::class, ChannelBroker::create('private-foo'));
    }

    public function testCanReturnAPresenceChannelInstance()
    {
        $this->assertInstanceOf(PresenceChannel::class, ChannelBroker::create('presence-foo'));
    }

    public function testCanReturnACacheChannelInstance()
    {
        $this->assertInstanceOf(CacheChannel::class, ChannelBroker::create('cache-foo'));
    }

    public function testCanReturnAPrivateCacheChannelInstance()
    {
        $this->assertInstanceOf(PrivateCacheChannel::class, ChannelBroker::create('private-cache-foo'));
    }

    public function testCanReturnAPresenceCacheChannelInstance()
    {
        $this->assertInstanceOf(PresenceCacheChannel::class, ChannelBroker::create('presence-cache-foo'));
    }
}
