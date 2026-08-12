<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\RateLimiter\Swoole\TableManager;

class InitializeSwooleTables
{
    /**
     * Create a new Swoole table initialization listener.
     */
    public function __construct(
        protected Repository $config,
        protected TableManager $tables,
    ) {
    }

    /**
     * Initialize every configured Swoole table before the server forks.
     */
    public function handle(BeforeServerStart $event): void
    {
        foreach ($this->config->array('rate-limiter.stores') as $name => $config) {
            if (! is_array($config) || ($config['driver'] ?? null) !== 'swoole') {
                continue;
            }

            $this->tables->get((string) $name);
        }

        $this->tables->seal();
    }
}
