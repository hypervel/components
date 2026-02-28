<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Framework\Events\BeforeWorkerStart;

/**
 * Clears database connection context when task workers start.
 *
 * Task workers run outside the normal coroutine context and must have their
 * database connection context cleared to prevent using stale connections
 * inherited from the parent process.
 */
class UnsetContextInTaskWorkerListener
{
    public function __construct(
        protected Container $container,
        protected Repository $config
    ) {
    }

    /**
     * Clear database connection context for task workers.
     */
    public function handle(BeforeWorkerStart $event): void
    {
        if (! $event->server->taskworker) {
            return;
        }

        $connectionResolver = $this->container->make(ConnectionResolverInterface::class);
        $connections = (array) $this->config->get('database.connections', []);

        foreach (array_keys($connections) as $name) {
            $contextKey = (fn () => $this->getContextKey($name))->call($connectionResolver); // @phpstan-ignore method.notFound (Closure::call() binds to concrete ConnectionResolver which has protected getContextKey())
            Context::destroy($contextKey);
        }
    }
}
