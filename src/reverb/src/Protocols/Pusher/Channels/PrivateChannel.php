<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Protocols\Pusher\Channels\Concerns\InteractsWithPrivateChannels;

class PrivateChannel extends Channel
{
    use InteractsWithPrivateChannels;
}
