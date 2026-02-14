<?php

declare(strict_types=1);

namespace Hypervel\View\Contracts;

use Closure;
use Hypervel\Support\Contracts\Arrayable;

interface Factory
{
    /**
     * Determine if a given view exists.
     */
    public function exists(string $view): bool;

    /**
     * Get the evaluated view contents for the given path.
     */
    public function file(string $path, Arrayable|array $data = [], array $mergeData = []): View;

    /**
     * Get the evaluated view contents for the given view.
     */
    public function make(string $view, Arrayable|array $data = [], array $mergeData = []): View;

    /**
     * Add a piece of shared data to the environment.
     */
    public function share(array|string $key, mixed $value = null): mixed;

    /**
     * Register a view composer event.
     */
    public function composer(array|string $views, Closure|string $callback): array;

    /**
     * Register a view creator event.
     */
    public function creator(array|string $views, Closure|string $callback): array;

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string|array $hints): static;

    /**
     * Replace the namespace hints for the given namespace.
     */
    public function replaceNamespace(string $namespace, string|array $hints): static;
}
