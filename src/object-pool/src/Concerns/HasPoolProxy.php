<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Concerns;

use Closure;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Support\Arr;
use InvalidArgumentException;

/**
 * Hosts must declare a protected array $poolableDrivers containing their default poolable drivers.
 */
trait HasPoolProxy
{
    /** @var array<string, Closure> */
    protected array $releaseCallbacks = [];

    /**
     * Create a pool proxy for an immutable definition.
     */
    protected function createPoolProxy(
        string $driver,
        Closure $createCallback,
        PoolDefinition $definition,
        string $proxyClass,
    ): mixed {
        if (! is_a($proxyClass, PoolProxy::class, true)) {
            throw new InvalidArgumentException('The pool proxy class must be an instance of ' . PoolProxy::class);
        }

        return new $proxyClass(
            $definition,
            $createCallback,
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
    public function addPoolableDriver(string $driver): static
    {
        if (! in_array($driver, $this->poolableDrivers, true)) {
            $this->poolableDrivers[] = $driver;
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
    public function removePoolableDriver(string $driver): static
    {
        $index = array_search($driver, $this->poolableDrivers, true);

        if ($index === false) {
            return $this;
        }

        unset($this->poolableDrivers[$index]);
        $this->poolableDrivers = array_values($this->poolableDrivers);

        return $this;
    }

    /**
     * Get the poolable-driver list.
     */
    public function getPoolableDrivers(): array
    {
        return $this->poolableDrivers;
    }

    /**
     * Set the poolable-driver list.
     *
     * Boot-only. The list persists on the manager for the worker lifetime and
     * is consulted on subsequent driver creation. Per-request use races across
     * coroutines and does not affect already-cached drivers.
     */
    public function setPoolableDrivers(array $poolableDrivers): static
    {
        $this->poolableDrivers = array_values($poolableDrivers);

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
