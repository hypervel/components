<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Context\Context as CoroutineContext;
use Hyperf\Contract\ConnectionInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Context helper for storing connections per-coroutine.
 */
class Context
{
    protected LoggerInterface $logger;

    public function __construct(
        protected ContainerInterface $container,
        protected string $name
    ) {
        $this->logger = $container->get(StdoutLoggerInterface::class);
    }

    /**
     * Get a connection from request context.
     */
    public function connection(): ?ConnectionInterface
    {
        if (CoroutineContext::has($this->name)) {
            return CoroutineContext::get($this->name);
        }

        return null;
    }

    /**
     * Set a connection in request context.
     */
    public function set(ConnectionInterface $connection): void
    {
        CoroutineContext::set($this->name, $connection);
    }
}
