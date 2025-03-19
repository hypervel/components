<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Traits;

use Closure;
use Hypervel\ObjectPool\PoolProxy;
use InvalidArgumentException;

trait HasPoolProxy
{
    /**
     * The array of release callbacks for the drivers.
     */
    protected array $releaseCallbacks = [];

    /**
     * Create a new pool proxy.
     */
    protected function createPoolProxy(string $driver, Closure $resolver, array $config = []): mixed
    {
        $proxyClass = property_exists($this, 'poolProxyClass')
            ? $this->poolProxyClass
            : PoolProxy::class;

        if (! is_subclass_of($proxyClass, PoolProxy::class)) {
            throw new InvalidArgumentException('The pool proxy class must be an instance of ' . PoolProxy::class);
        }

        return new $proxyClass(
            static::class . ':' . $driver,
            $resolver,
            $config,
            $this->getReleaseCallback($driver)
        );
    }

    /**
     * Set the release callback for the driver.
     */
    public function setReleaseCallback(string $driver, Closure $callback): static
    {
        $this->releaseCallbacks[$driver] = $callback;

        return $this;
    }

    /**
     * Get the release callback for the driver.
     */
    public function getReleaseCallback(string $driver): ?Closure
    {
        return $this->releaseCallbacks[$driver] ?? null;
    }

    /**
     * Add a driver to the poolables list.
     */
    public function addPoolable(string $driver): static
    {
        if (! in_array($driver, $this->poolables)) {
            $this->poolables[] = $driver;
        }

        return $this;
    }

    /**
     * Remove a driver from the poolables list.
     */
    public function removePoolable(string $driver): static
    {
        $index = array_search($driver, $this->poolables);
        if ($index === false) {
            return $this;
        }

        unset($this->poolables[$index]);

        return $this;
    }

    /**
     * Get the poolables list.
     */
    public function getPoolables(): array
    {
        return $this->poolables;
    }

    /**
     * Set the poolables list.
     */
    public function setPoolables(array $poolables): static
    {
        $this->poolables = $poolables;

        return $this;
    }
}
