<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Translation;

use Hypervel\Support\Attributes\BootOnly;

interface Loader
{
    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array;

    /**
     * Add a new namespace to the loader.
     */
    #[BootOnly(whenShared: true)]
    public function addNamespace(string $namespace, string $hint): void;

    /**
     * Add a new JSON path to the loader.
     */
    #[BootOnly(whenShared: true)]
    public function addJsonPath(string $path): void;

    /**
     * Add a new path to the loader.
     */
    #[BootOnly(whenShared: true)]
    public function addPath(string $path): void;

    /**
     * Get an array of all the registered namespaces.
     *
     * @return array<string, string>
     */
    public function namespaces(): array;
}
