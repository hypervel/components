<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\PoolDefinition;

interface Factory
{
    /**
     * Get or create the pool registered for an immutable definition.
     */
    public function getOrCreate(
        PoolDefinition $definition,
        callable $callback,
    ): ObjectPool;

    /**
     * Get a managed pool by identity.
     */
    public function get(string $identity): ObjectPool;

    /**
     * Determine if a pool exists for an identity.
     */
    public function has(string $identity): bool;

    /**
     * Get all registered pools keyed by identity.
     *
     * @return array<string, ObjectPool>
     */
    public function pools(): array;

    /**
     * Get the definition registered for an identity.
     */
    public function definition(string $identity): ?PoolDefinition;

    /**
     * Remove and close a pool when it still matches an optional expected instance.
     */
    public function remove(string $identity, ?ObjectPool $expected = null): bool;

    /**
     * Remove and close every registered pool.
     *
     * Boot or tests only. This clears worker-lifetime pools shared by every
     * coroutine; use targeted removal for runtime resource recovery.
     */
    public function flush(): void;
}
