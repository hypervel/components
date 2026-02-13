<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Container\Container;

class ClientRequestWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Container $app): void
    {
        // The real class of handling client request is
        // `Hypervel\Telescope\Aspects\GuzzleHttpClientAspect::class`
    }
}
