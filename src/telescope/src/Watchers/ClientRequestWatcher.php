<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Psr\Container\ContainerInterface;

class ClientRequestWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        // The real class of handling client request is
        // `Hypervel\Telescope\Aspects\GuzzleHttpClientAspect::class`
    }
}
