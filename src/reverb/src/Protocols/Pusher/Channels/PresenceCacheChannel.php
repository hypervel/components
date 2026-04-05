<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceCacheChannel extends CacheChannel
{
    use InteractsWithPresenceChannels;
}
