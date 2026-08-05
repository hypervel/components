<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\ObjectPool\Contracts\Factory as FactoryContract;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use JsonException;
use RuntimeException;

class PoolManager implements FactoryContract
{
    /** @var array<string, ObjectPool> */
    protected array $pools = [];

    /** @var array<string, PoolDefinition> */
    protected array $definitions = [];

    /**
     * Get or create a named pool.
     *
     * The name declares construction equivalence: every callback used with it
     * must construct an equivalent object. Resolve the pool each time you
     * borrow, because idle managed pools may be removed and closed.
     */
    public function pool(
        string $name,
        callable $callback,
        array $options = [],
    ): ObjectPool {
        return $this->getOrCreate(
            new PoolDefinition(
                identity: $name,
                resourceType: $name,
                fingerprint: PoolFingerprint::fromExplicit($name),
                options: PoolOptions::fromArray($options),
            ),
            $callback,
        );
    }

    /**
     * Get or create the pool registered for an immutable definition.
     */
    public function getOrCreate(
        PoolDefinition $definition,
        callable $callback,
    ): ObjectPool {
        $identity = $definition->identity;

        if (($pool = $this->pools[$identity] ?? null) !== null) {
            if ($pool->isClosed()) {
                unset($this->pools[$identity], $this->definitions[$identity]);
            } else {
                $current = $this->definitions[$identity];

                if ($current->resourceType !== $definition->resourceType) {
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists for resource type [{$current->resourceType}]; "
                        . "requested [{$definition->resourceType}]. Explicit pool identities never span resource types."
                    );
                }

                if ($current->fingerprint !== $definition->fingerprint) {
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists with a different construction fingerprint "
                        . "[{$current->fingerprint}] (requested [{$definition->fingerprint}]). "
                        . 'Purge the pool or use a distinct explicit pool name.'
                    );
                }

                if (! $current->options->equals($definition->options)) {
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists with different options: "
                        . $this->diffOptions($current->options, $definition->options)
                        . '. Align the pool options or use a distinct explicit pool name.'
                    );
                }

                return $pool;
            }
        }

        $pool = new SimpleObjectPool($callback, $definition->options);

        $this->definitions[$identity] = $definition;

        return $this->pools[$identity] = $pool;
    }

    /**
     * Get a currently registered managed pool by identity.
     */
    public function get(string $identity): ObjectPool
    {
        if (($pool = $this->pools[$identity] ?? null) === null) {
            throw new RuntimeException("Pool [{$identity}] does not exist.");
        }

        return $pool;
    }

    /**
     * Determine if a pool is currently registered for an identity.
     */
    public function has(string $identity): bool
    {
        return isset($this->pools[$identity]);
    }

    /**
     * Get all registered pools keyed by identity.
     *
     * @return array<string, ObjectPool>
     */
    public function pools(): array
    {
        return $this->pools;
    }

    /**
     * Get the definition currently registered for an identity.
     */
    public function definition(string $identity): ?PoolDefinition
    {
        return $this->definitions[$identity] ?? null;
    }

    /**
     * Remove and close a pool when it still matches an optional expected instance.
     */
    public function remove(string $identity, ?ObjectPool $expected = null): bool
    {
        $pool = $this->pools[$identity] ?? null;

        if ($pool === null || ($expected !== null && $pool !== $expected)) {
            return false;
        }

        unset($this->pools[$identity], $this->definitions[$identity]);
        $pool->close();

        return true;
    }

    /**
     * Remove and close every registered pool.
     *
     * Boot or tests only. This clears worker-lifetime pools shared by every
     * coroutine; use targeted removal for runtime resource recovery.
     */
    public function flush(): void
    {
        $pools = $this->pools;
        $this->pools = [];
        $this->definitions = [];

        foreach ($pools as $pool) {
            $pool->close();
        }
    }

    /**
     * Describe only normalized pool-option differences.
     *
     * @throws JsonException
     */
    protected function diffOptions(PoolOptions $registered, PoolOptions $requested): string
    {
        $differences = [];
        $requestedOptions = $requested->toArray();

        foreach ($registered->toArray() as $name => $value) {
            $requestedValue = $requestedOptions[$name];

            if ($value !== $requestedValue) {
                $differences[$name] = [
                    'registered' => $value,
                    'requested' => $requestedValue,
                ];
            }
        }

        return json_encode($differences, JSON_THROW_ON_ERROR);
    }
}
