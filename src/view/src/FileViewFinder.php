<?php

declare(strict_types=1);

namespace Hypervel\View;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Filesystem\Filesystem;
use InvalidArgumentException;

class FileViewFinder implements ViewFinderInterface
{
    /**
     * Context key for temporarily scoped namespace hints.
     */
    protected const NAMESPACE_OVERRIDE_CONTEXT_KEY = '__view.namespace_override';

    /**
     * The array of active view paths.
     */
    protected array $paths;

    /**
     * The array of views that have been located.
     */
    protected array $views = [];

    /**
     * The namespace to file path hints.
     */
    protected array $hints = [];

    /**
     * Register a view extension with the finder.
     *
     * @var string[]
     */
    protected array $extensions = ['blade.php', 'php', 'css', 'html'];

    /**
     * Create a new file view loader instance.
     */
    public function __construct(
        protected Filesystem $files,
        array $paths,
        ?array $extensions = null
    ) {
        $this->paths = array_map([$this, 'resolvePath'], $paths);

        if (isset($extensions)) {
            $this->extensions = $extensions;
        }
    }

    /**
     * Get the fully qualified location of the view.
     */
    public function find(string $name): string
    {
        $hasScopedNamespaceOverride = $this->hasScopedNamespaceOverride($name);

        // Scoped namespace hints are coroutine-local, so a cached namespaced path
        // may belong to another render scope and must not be reused here.
        if (isset($this->views[$name]) && ! $hasScopedNamespaceOverride) {
            return $this->views[$name];
        }

        if ($this->hasHintInformation($name = trim($name))) {
            $path = $this->findNamespacedView($name);

            return $hasScopedNamespaceOverride ? $path : $this->views[$name] = $path;
        }

        return $this->views[$name] = $this->findInPaths($name, $this->paths);
    }

    /**
     * Get the path to a template with a named path.
     */
    protected function findNamespacedView(string $name): string
    {
        [$namespace, $view] = $this->parseNamespaceSegments($name);
        $hints = $this->resolveNamespaceHints($namespace);
        /** @var array $hints */

        return $this->findInPaths($view, $hints);
    }

    /**
     * Get the segments of a template with a named path.
     *
     * @throws InvalidArgumentException
     */
    protected function parseNamespaceSegments(string $name): array
    {
        $segments = explode(static::HINT_PATH_DELIMITER, $name);

        if (count($segments) !== 2) {
            throw new InvalidArgumentException("View [{$name}] has an invalid name.");
        }

        if (is_null($this->resolveNamespaceHints($segments[0]))) {
            throw new InvalidArgumentException("No hint path defined for [{$segments[0]}].");
        }

        return $segments;
    }

    /**
     * Resolve namespace hints, including any current coroutine-local override.
     */
    protected function resolveNamespaceHints(string $namespace): ?array
    {
        $overrides = CoroutineContext::get(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, []);

        return array_key_exists($namespace, $overrides)
            ? $overrides[$namespace]
            : $this->hints[$namespace] ?? null;
    }

    /**
     * Find the given view in the list of paths.
     *
     * @throws InvalidArgumentException
     */
    protected function findInPaths(string $name, array $paths): string
    {
        foreach ((array) $paths as $path) {
            foreach ($this->getPossibleViewFiles($name) as $file) {
                $viewPath = $path . '/' . $file;

                if (strlen($viewPath) < (PHP_MAXPATHLEN - 1) && $this->files->exists($viewPath)) {
                    return $viewPath;
                }
            }
        }

        throw new InvalidArgumentException("View [{$name}] not found.");
    }

    /**
     * Get an array of possible view files.
     */
    protected function getPossibleViewFiles(string $name): array
    {
        return array_map(fn ($extension) => str_replace('.', '/', $name) . '.' . $extension, $this->extensions);
    }

    /**
     * Add a location to the finder.
     */
    public function addLocation(string $location): void
    {
        $this->paths[] = $this->resolvePath($location);
    }

    /**
     * Prepend a location to the finder.
     */
    public function prependLocation(string $location): void
    {
        array_unshift($this->paths, $this->resolvePath($location));
    }

    /**
     * Resolve the path.
     */
    protected function resolvePath(string $path): string
    {
        return realpath($path) ?: $path;
    }

    /**
     * Add a namespace hint to the finder.
     */
    public function addNamespace(string $namespace, string|array $hints): void
    {
        $hints = (array) $hints;

        if (isset($this->hints[$namespace])) {
            $hints = array_merge($this->hints[$namespace], $hints);
        }

        $this->hints[$namespace] = $hints;
    }

    /**
     * Prepend a namespace hint to the finder.
     */
    public function prependNamespace(string $namespace, string|array $hints): void
    {
        $hints = (array) $hints;

        if (isset($this->hints[$namespace])) {
            $hints = array_merge($hints, $this->hints[$namespace]);
        }

        $this->hints[$namespace] = $hints;
    }

    /**
     * Replace the namespace hints for the given namespace.
     */
    public function replaceNamespace(string $namespace, string|array $hints): void
    {
        $this->hints[$namespace] = (array) $hints;
    }

    /**
     * Execute the given callback with a temporary namespace hint.
     */
    public function scopedNamespace(string $namespace, string|array $hints, Closure $callback): mixed
    {
        $overrides = CoroutineContext::get(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, []);
        $hadPreviousHints = array_key_exists($namespace, $overrides);
        $previousHints = $overrides[$namespace] ?? null;

        $overrides[$namespace] = (array) $hints;
        CoroutineContext::set(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, $overrides);

        try {
            return $callback();
        } finally {
            $overrides = CoroutineContext::get(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, []);

            if ($hadPreviousHints) {
                $overrides[$namespace] = $previousHints;
            } else {
                unset($overrides[$namespace]);
            }

            if ($overrides === []) {
                CoroutineContext::forget(self::NAMESPACE_OVERRIDE_CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, $overrides);
            }
        }
    }

    /**
     * Register an extension with the view finder.
     */
    public function addExtension(string $extension): void
    {
        if (($index = array_search($extension, $this->extensions)) !== false) {
            unset($this->extensions[$index]);
        }

        array_unshift($this->extensions, $extension);
    }

    /**
     * Returns whether or not the view name has any hint information.
     */
    public function hasHintInformation(string $name): bool
    {
        return strpos($name, static::HINT_PATH_DELIMITER) > 0;
    }

    /**
     * Determine whether the view's namespace has a scoped override.
     */
    protected function hasScopedNamespaceOverride(string $name): bool
    {
        if (! $this->hasHintInformation($name = trim($name))) {
            return false;
        }

        if (! CoroutineContext::has(self::NAMESPACE_OVERRIDE_CONTEXT_KEY)) {
            return false;
        }

        $segments = explode(static::HINT_PATH_DELIMITER, $name);

        if (count($segments) !== 2) {
            return false;
        }

        return array_key_exists(
            $segments[0],
            CoroutineContext::get(self::NAMESPACE_OVERRIDE_CONTEXT_KEY, [])
        );
    }

    /**
     * Flush the cache of located views.
     */
    public function flush(): void
    {
        $this->views = [];
    }

    /**
     * Get the filesystem instance.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->files;
    }

    /**
     * Set the active view paths.
     */
    public function setPaths(array $paths): static
    {
        $this->paths = $paths;

        return $this;
    }

    /**
     * Get the active view paths.
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Get the views that have been located.
     */
    public function getViews(): array
    {
        return $this->views;
    }

    /**
     * Get the namespace to file path hints.
     */
    public function getHints(): array
    {
        return $this->hints;
    }

    /**
     * Get registered extensions.
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }
}
