<?php

declare(strict_types=1);

namespace Hypervel\Database\Listeners;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hypervel\Context\Context;
use Hypervel\Database\ConnectionResolverInterface;
use Psr\Container\ContainerInterface;

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
        protected ContainerInterface $container,
        protected ConfigInterface $config
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

        $connectionResolver = $this->container->get(ConnectionResolverInterface::class);
        $connections = (array) $this->config->get('database.connections', []);

        foreach (array_keys($connections) as $name) {
            $contextKey = (fn () => $this->getContextKey($name))->call($connectionResolver);
            Context::destroy($contextKey);
        }
    }
}
