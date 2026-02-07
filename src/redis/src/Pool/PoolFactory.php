<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container as ContainerContract;

class PoolFactory
{
    /**
     * @var RedisPool[]
     */
    protected array $pools = [];

    public function __construct(
        protected ContainerContract $container
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

        return $this->pools[$name] = $this->container->make(RedisPool::class, ['name' => $name]);
    }
}
