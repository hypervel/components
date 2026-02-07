<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hyperf\Di\Container;
use Psr\Container\ContainerInterface;

class PoolFactory
{
    /**
     * @var RedisPool[]
     */
    protected array $pools = [];

    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    /**
     * Get or create a pool for the given connection name.
     */
    public function getPool(string $name): RedisPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        if ($this->container instanceof Container) {
            $pool = $this->container->make(RedisPool::class, ['name' => $name]);
        } else {
            $pool = new RedisPool($this->container, $name);
        }

        return $this->pools[$name] = $pool;
    }
}
