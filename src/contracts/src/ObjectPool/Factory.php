<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ObjectPool;

use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\Support\Attributes\BootOrTestsOnly;

interface Factory
{
    /**
     * Get or create a named pool.
     *
     * The name declares construction equivalence: every callback used with it
     * must construct an equivalent object. Resolve the pool each time you
     * borrow, because idle managed pools may be removed and closed.
     */
    public function pool(
        string $name,
        callable $createCallback,
        array $options = [],
    ): ObjectPool;

    /**
     * Get or create the pool registered for an immutable definition.
     */
    public function getOrCreate(
        PoolDefinition $definition,
        callable $createCallback,
    ): ObjectPool;

    /**
     * Get a currently registered managed pool by identity.
     */
    public function get(string $identity): ObjectPool;

    /**
     * Determine if a pool is currently registered for an identity.
     *
     * This does not reserve registry membership across a coroutine yield.
     */
    public function has(string $identity): bool;

    /**
     * Get all registered pools keyed by identity.
     *
     * @return array<string, ObjectPool>
     */
    public function getPools(): array;

    /**
     * Get the definition currently registered for an identity.
     */
    public function getDefinition(string $identity): ?PoolDefinition;

    /**
     * Remove and close a pool when it still matches an optional expected instance.
     */
    public function purge(string $identity, ?ObjectPool $expected = null): bool;

    /**
     * Remove and close every registered pool.
     *
     * This clears worker-lifetime pools shared by every coroutine; use
     * targeted removal for runtime resource recovery.
     */
    #[BootOrTestsOnly]
    public function purgeAll(): void;
}
