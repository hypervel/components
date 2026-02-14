<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Config\Repository;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionResolverInterface;

/**
 * Clears database connection context when task workers start.
 *
 * Task workers run outside the normal coroutine context and must have their
 * database connection context cleared to prevent using stale connections
 * inherited from the parent process.
 */
class UnsetContextInTaskWorkerListener implements ListenerInterface
{
    public function __construct(
        protected Container $container,
        protected Repository $config
    ) {
    }

    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        if (! $event instanceof BeforeWorkerStart || ! $event->server->taskworker) {
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
