<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Support\Str;

class ChannelBroker
{
    /**
     * Return the relevant channel instance.
     */
    public static function create(string $name): Channel
    {
        return match (true) {
            Str::startsWith($name, 'private-cache-') => new PrivateCacheChannel($name),
            Str::startsWith($name, 'presence-cache-') => new PresenceCacheChannel($name),
            Str::startsWith($name, 'cache') => new CacheChannel($name),
            Str::startsWith($name, 'private') => new PrivateChannel($name),
            Str::startsWith($name, 'presence') => new PresenceChannel($name),
            default => new Channel($name),
        };
    }
}
