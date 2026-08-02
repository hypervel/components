<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Contracts;

use Hypervel\Reverb\Application;

interface ChannelManager
{
    /**
     * Get a scoped channel manager for the given application.
     */
    public function for(Application $application): ScopedChannelManager;

    /**
     * Flush the channel manager repository.
     */
    public function flush(): void;
}
