<?php

declare(strict_types=1);

namespace Hypervel\Translation\Contracts;

interface Loader
{
    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array;

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string $hint): void;

    /**
     * Add a new JSON path to the loader.
     */
    public function addJsonPath(string $path): void;

    /**
     * Add a new path to the loader.
     */
    public function addPath(string $path): void;

    /**
     * Get an array of all the registered namespaces.
     */
    public function namespaces(): array;
}
