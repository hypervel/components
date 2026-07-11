<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Traits;

use Closure;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Support\Arr;
use InvalidArgumentException;

trait HasPoolProxy
{
    /** @var array<string, Closure> */
    protected array $releaseCallbacks = [];

    /**
     * Create a pool proxy for an immutable definition.
     */
    protected function createPoolProxy(
        string $driver,
        Closure $resolver,
        PoolDefinition $definition,
        string $proxyClass,
    ): mixed {
        if (! is_a($proxyClass, PoolProxy::class, true)) {
            throw new InvalidArgumentException('The pool proxy class must be an instance of ' . PoolProxy::class);
        }

        return new $proxyClass(
            $definition,
            $resolver,
            $this->poolFactory(),
            $this->getReleaseCallback($driver),
        );
    }

    /**
     * Build a namespaced pool definition for a pooled resource.
     */
    protected function poolDefinition(string $resource, array $poolConfig, array $fingerprintSource): PoolDefinition
    {
        $explicitName = $this->poolControlString($poolConfig, 'name');
        $explicitFingerprint = $this->poolControlString($poolConfig, 'fingerprint');
        $options = PoolOptions::fromArray(Arr::except($poolConfig, ['name', 'fingerprint']));
        $fingerprint = $explicitFingerprint !== null
            ? PoolFingerprint::fromExplicit($explicitFingerprint)
            : PoolFingerprint::fromConfig($fingerprintSource);
        $identity = $explicitName !== null
            ? static::class . ':named:' . $explicitName
            : static::class . ':auto:' . $resource . ':' . $fingerprint;

        return new PoolDefinition($identity, $resource, $fingerprint, $options);
    }

    /**
     * Get the pool factory used by this manager.
     */
    abstract protected function poolFactory(): Factory;

    /**
     * Set the release callback for a pooled driver.
     *
     * Boot-only. The callback persists on the manager for the worker lifetime
     * and is captured by every subsequently created proxy for the driver.
     */
    public function setReleaseCallback(string $driver, Closure $callback): static
    {
        $this->releaseCallbacks[$driver] = $callback;

        return $this;
    }

    /**
     * Get the release callback for a pooled driver.
     */
    public function getReleaseCallback(string $driver): ?Closure
    {
        return $this->releaseCallbacks[$driver] ?? null;
    }

    /**
     * Add a driver to the poolable-driver list.
     *
     * Boot-only. The list persists on the manager for the worker lifetime and
     * is consulted on subsequent driver creation. Per-request use races across
     * coroutines and does not affect already-cached drivers.
     */
    public function addPoolable(string $driver): static
    {
        if (! in_array($driver, $this->poolables, true)) {
            $this->poolables[] = $driver;
        }

        return $this;
    }

    /**
     * Remove a driver from the poolable-driver list.
     *
     * Boot-only. The list persists on the manager for the worker lifetime and
     * is consulted on subsequent driver creation. Per-request use races across
     * coroutines and does not affect already-cached drivers.
     */
    public function removePoolable(string $driver): static
    {
        $index = array_search($driver, $this->poolables, true);

        if ($index === false) {
            return $this;
        }

        unset($this->poolables[$index]);
        $this->poolables = array_values($this->poolables);

        return $this;
    }

    /**
     * Get the poolable-driver list.
     */
    public function getPoolables(): array
    {
        return $this->poolables;
    }

    /**
     * Set the poolable-driver list.
     *
     * Boot-only. The list persists on the manager for the worker lifetime and
     * is consulted on subsequent driver creation. Per-request use races across
     * coroutines and does not affect already-cached drivers.
     */
    public function setPoolables(array $poolables): static
    {
        $this->poolables = array_values($poolables);

        return $this;
    }

    /**
     * Read and validate an optional string pool-control field.
     */
    private function poolControlString(array $poolConfig, string $name): ?string
    {
        if (! array_key_exists($name, $poolConfig)) {
            return null;
        }

        $value = $poolConfig[$name];

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The pool [{$name}] option must be a non-empty string.");
        }

        return $value;
    }
}
