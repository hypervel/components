<?php

declare(strict_types=1);

namespace Hypervel\Console\Events;

use Hypervel\Console\Application;

class ArtisanStarting
{
    /**
     * Create a new event instance.
     *
     * @param \Hypervel\Console\Application $artisan the Artisan application instance
     */
    public function __construct(
        public Application $artisan,
    ) {
    }
}
