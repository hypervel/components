<?php

declare(strict_types=1);

namespace Hypervel\View;

use Hypervel\Filesystem\Filesystem;
use InvalidArgumentException;

class FileViewFinder implements ViewFinderInterface
{
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
    public function find(string $view): string
    {
        if (isset($this->views[$view])) {
            return $this->views[$view];
        }

        if ($this->hasHintInformation($view = trim($view))) {
            return $this->views[$view] = $this->findNamespacedView($view);
        }

        return $this->views[$view] = $this->findInPaths($view, $this->paths);
    }

    /**
     * Get the path to a template with a named path.
     */
    protected function findNamespacedView(string $name): string
    {
        [$namespace, $view] = $this->parseNamespaceSegments($name);

        return $this->findInPaths($view, $this->hints[$namespace]);
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

        if (! isset($this->hints[$segments[0]])) {
            throw new InvalidArgumentException("No hint path defined for [{$segments[0]}].");
        }

        return $segments;
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
     *
     * Boot-only. Request-time use changes shared view paths while concurrent
     * coroutines may still be resolving views.
     */
    public function addLocation(string $location): void
    {
        $this->paths[] = $this->resolvePath($location);
    }

    /**
     * Prepend a location to the finder.
     *
     * Boot-only. Request-time use changes shared view paths while concurrent
     * coroutines may still be resolving views.
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
     *
     * Boot-only. Request-time use changes shared view hints while concurrent
     * coroutines may still be resolving views.
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
     *
     * Boot-only. Request-time use changes shared view hints while concurrent
     * coroutines may still be resolving views.
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
     *
     * Boot-only. Request-time use changes shared view hints while concurrent
     * coroutines may still be resolving views.
     */
    public function replaceNamespace(string $namespace, string|array $hints): void
    {
        $this->hints[$namespace] = (array) $hints;
    }

    /**
     * Register an extension with the view finder.
     *
     * Boot-only. Request-time use changes shared view extensions while
     * concurrent coroutines may still be resolving views.
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
     * Flush the cache of located views.
     *
     * Boot or tests only. Request-time use clears shared lookup state while
     * concurrent coroutines may still be resolving views.
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
     *
     * Boot-only. Request-time use changes shared view paths while concurrent
     * coroutines may still be resolving views.
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
