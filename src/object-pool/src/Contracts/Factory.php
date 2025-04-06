<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface Factory
{
    /**
     * Get a managed pool by name.
     */
    public function get(string $name): ObjectPool;

    /**
     * Create and register a new object pool.
     */
    public function create(string $name, callable $callback, array $options = []): ObjectPool;

    /**
     * Get all registered pools.
     */
    public function pools(): array;

    /**
     * Check if a pool exists.
     */
    public function has(string $name): bool;

    /**
     * Remove a pool from the manager.
     */
    public function remove(string $name): static;
}
