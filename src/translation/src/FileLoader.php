<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Translation\Contracts\Loader;
use RuntimeException;

class FileLoader implements Loader
{
    /**
     * The default paths for the loader.
     */
    protected array $paths = [];

    /**
     * All of the registered paths to JSON translation files.
     */
    protected array $jsonPaths = [];

    /**
     * All of the namespace hints.
     */
    protected array $hints = [];

    /**
     * Create a new file loader instance.
     *
     * @param Filesystem $files the filesystem instance
     */
    public function __construct(
        protected Filesystem $files,
        array|string $path
    ) {
        $this->files = $files;

        $this->paths = is_string($path) ? [$path] : $path;
    }

    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        if ($group === '*' && $namespace === '*') {
            return $this->loadJsonPaths($locale);
        }

        if (is_null($namespace) || $namespace === '*') {
            return $this->loadPaths($this->paths, $locale, $group);
        }

        return $this->loadNamespaced($locale, $group, $namespace);
    }

    /**
     * Load a namespaced translation group.
     */
    protected function loadNamespaced(string $locale, string $group, string $namespace): array
    {
        if (isset($this->hints[$namespace])) {
            $lines = $this->loadPaths([$this->hints[$namespace]], $locale, $group);

            return $this->loadNamespaceOverrides($lines, $locale, $group, $namespace);
        }

        return [];
    }

    /**
     * Load a local namespaced translation group for overrides.
     */
    protected function loadNamespaceOverrides(array $lines, string $locale, string $group, string $namespace): array
    {
        return (new Collection($this->paths))
            ->reduce(function ($output, $path) use ($locale, $group, $namespace) {
                $file = "{$path}/vendor/{$namespace}/{$locale}/{$group}.php";

                if ($this->files->exists($file)) {
                    $output = array_replace_recursive($output, $this->files->getRequire($file));
                }

                return $output;
            }, $lines);
    }

    /**
     * Load a locale from a given path.
     */
    protected function loadPaths(array $paths, string $locale, string $group): array
    {
        return (new Collection($paths))
            ->reduce(function ($output, $path) use ($locale, $group) {
                if ($this->files->exists($full = "{$path}/{$locale}/{$group}.php")) {
                    $output = array_replace_recursive($output, $this->files->getRequire($full));
                }

                return $output;
            }, []);
    }

    /**
     * Load a locale from the given JSON file path.
     *
     * @throws RuntimeException
     */
    protected function loadJsonPaths(string $locale): array
    {
        return (new Collection(array_merge($this->jsonPaths, $this->paths)))
            ->reduce(function ($output, $path) use ($locale) {
                if ($this->files->exists($full = "{$path}/{$locale}.json")) {
                    $decoded = json_decode($this->files->get($full), true);

                    if (is_null($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                        throw new RuntimeException("Translation file [{$full}] contains an invalid JSON structure.");
                    }

                    $output = array_merge($output, $decoded);
                }

                return $output;
            }, []);
    }

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string $hint): void
    {
        $this->hints[$namespace] = $hint;
    }

    /**
     * Get an array of all the registered namespaces.
     */
    public function namespaces(): array
    {
        return $this->hints;
    }

    /**
     * Add a new path to the loader.
     */
    public function addPath(string $path): void
    {
        $this->paths[] = $path;
    }

    /**
     * Add a new JSON path to the loader.
     */
    public function addJsonPath(string $path): void
    {
        $this->jsonPaths[] = $path;
    }

    /**
     * Get an array of all the registered paths to translation files.
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * Get an array of all the registered paths to JSON translation files.
     */
    public function jsonPaths(): array
    {
        return $this->jsonPaths;
    }
}
