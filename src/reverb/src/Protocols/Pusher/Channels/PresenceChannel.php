<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\Concerns\InteractsWithPresenceChannels;

class PresenceChannel extends PrivateChannel
{
    use InteractsWithPresenceChannels;
}
