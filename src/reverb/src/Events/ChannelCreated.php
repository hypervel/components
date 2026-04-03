<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Events;

use Hypervel\Foundation\Events\Dispatchable;
use Hypervel\Reverb\Protocols\Pusher\Channels\Channel;

class ChannelCreated
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Channel $channel)
    {
    }
}
