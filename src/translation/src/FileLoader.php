<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Translation\Loader;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
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
     *
     * @var array<string, string>
     */
    protected array $hints = [];

    /**
     * Create a new file loader instance.
     */
    public function __construct(
        protected Filesystem $files,
        array|string $path
    ) {
        $this->paths = is_string($path) ? [$path] : $path;
    }

    /**
     * Load the messages for the given locale.
     */
    public function load(string $locale, string $group, ?string $namespace = null): array
    {
        // Mirrors the eager check in Translator::setLocale(); keep both predicates identical.
        if (Str::contains($locale, ['/', '\\']) || $locale === '.' || $locale === '..') {
            throw new InvalidArgumentException('Invalid characters present in locale.');
        }

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

                    if (! is_array($decoded)) {
                        throw new RuntimeException("Translation file [{$full}] contains an invalid JSON structure.");
                    }

                    foreach ($decoded as $key => $value) {
                        if ($value !== null && ! is_string($value) && ! is_array($value)) {
                            throw new RuntimeException(
                                "Translation file [{$full}] contains an invalid value for key [{$key}]. Translation values must be strings or arrays."
                            );
                        }
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
     *
     * @return array<string, string>
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
