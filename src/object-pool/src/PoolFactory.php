<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Psr\Container\ContainerInterface;

class PoolFactory
{
    protected array $pools = [];

    public function __construct(protected ContainerInterface $container)
    {
    }

    public function get(string $name, callable $callback, array $options = []): ObjectPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        $pool = new SimpleObjectPool(
            $this->container,
            $callback,
            $options
        );

        return $this->pools[$name] = $pool;
    }
}
