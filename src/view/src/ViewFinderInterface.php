<?php

declare(strict_types=1);

namespace Hypervel\View;

interface ViewFinderInterface
{
    /**
     * Hint path delimiter value.
     */
    public const HINT_PATH_DELIMITER = '::';

    /**
     * Get the fully qualified location of the view.
     */
    public function find(string $view): string;

    /**
     * Add a location to the finder.
     */
    public function addLocation(string $location): void;

    /**
     * Add a namespace hint to the finder.
     */
    public function addNamespace(string $namespace, string|array $hints): void;

    /**
     * Prepend a namespace hint to the finder.
     */
    public function prependNamespace(string $namespace, string|array $hints): void;

    /**
     * Replace the namespace hints for the given namespace.
     */
    public function replaceNamespace(string $namespace, string|array $hints): void;

    /**
     * Add a valid view extension to the finder.
     */
    public function addExtension(string $extension): void;

    /**
     * Flush the cache of located views.
     */
    public function flush(): void;

    /**
     * Prepend a location to the finder.
     */
    public function prependLocation(string $location): void;
}
