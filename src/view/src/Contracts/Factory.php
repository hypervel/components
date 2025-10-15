<?php

declare(strict_types=1);

namespace Hypervel\View\Contracts;

interface Factory
{
    /**
     * Determine if a given view exists.
     *
     * @param  string  $view
     * @return bool
     */
    public function exists(string $view): bool;

    /**
     * Get the evaluated view contents for the given path.
     *
     * @param  string  $path
     * @param  \Hypervel\Contracts\Support\Arrayable|array  $data
     * @param  array  $mergeData
     * @return \Hypervel\Contracts\View\View
     */
    public function file(string $path, $data = [], array $mergeData = []): View;

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string  $view
     * @param  \Hypervel\Contracts\Support\Arrayable|array  $data
     * @param  array  $mergeData
     * @return \Hypervel\Contracts\View\View
     */
    public function make(string $view, $data = [], array $mergeData = []): View;

    /**
     * Add a piece of shared data to the environment.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function share(array|string $key, mixed $value = null): mixed;

    /**
     * Register a view composer event.
     *
     * @param  array|string  $views
     * @param  \Closure|string  $callback
     * @return array
     */
    public function composer(array|string $views, \Closure|string $callback): array;

    /**
     * Register a view creator event.
     *
     * @param  array|string  $views
     * @param  \Closure|string  $callback
     * @return array
     */
    public function creator(array|string $views, \Closure|string $callback): array;

    /**
     * Add a new namespace to the loader.
     *
     * @param  string  $namespace
     * @param  string|array  $hints
     * @return $this
     */
    public function addNamespace(string $namespace, string|array $hints): static;

    /**
     * Replace the namespace hints for the given namespace.
     *
     * @param  string  $namespace
     * @param  string|array  $hints
     * @return $this
     */
    public function replaceNamespace(string $namespace, string|array $hints): static;
}
