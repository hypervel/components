<?php

declare(strict_types=1);

namespace Hypervel\View;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Hypervel\View\Contracts\Engine;
use Hypervel\View\Contracts\Factory as FactoryContract;
use Hypervel\View\Contracts\View as ViewContract;
use Hypervel\View\Engines\EngineResolver;
use InvalidArgumentException;

class Factory implements FactoryContract
{
    use Macroable;
    use Concerns\ManagesComponents;
    use Concerns\ManagesEvents;
    use Concerns\ManagesFragments;
    use Concerns\ManagesLayouts;
    use Concerns\ManagesLoops;
    use Concerns\ManagesStacks;
    use Concerns\ManagesTranslations;

    /**
     * The number of active rendering operations.
     */
    protected const RENDER_COUNT_CONTEXT_KEY = 'render_count';

    /**
     * The "once" block IDs that have been rendered.
     */
    protected const RENDERED_ONCE_CONTEXT_KEY = 'rendered_once';

    /**
     * The IoC container instance.
     */
    protected Container $container;

    /**
     * Data that should be available to all templates.
     */
    protected array $shared = [];

    /**
     * The extension to engine bindings.
     */
    protected array $extensions = [
        'blade.php' => 'blade',
        'php' => 'php',
        'css' => 'file',
        'html' => 'file',
    ];

    /**
     * The view composer events.
     */
    protected array $composers = [];

    /**
     * The cached array of engines for paths.
     */
    protected array $pathEngineCache = [];

    /**
     * The cache of normalized names for views.
     */
    protected array $normalizedNameCache = [];

    /**
     * Create a new view factory instance.
     */
    public function __construct(
        protected EngineResolver $engines,
        protected ViewFinderInterface $finder,
        protected Dispatcher $events
    ) {
        $this->share('__env', $this);
    }

    /**
     * Get the evaluated view contents for the given view.
     */
    public function file(string $path, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        $data = array_merge($mergeData, $this->parseData($data));

        return tap($this->viewInstance($path, $path, $data), function ($view) {
            $this->callCreator($view);
        });
    }

    /**
     * Get the evaluated view contents for the given view.
     */
    public function make(string $view, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        $path = $this->finder->find(
            $view = $this->normalizeName($view)
        );

        // Next, we will create the view instance and call the view creator for the view
        // which can set any data, etc. Then we will return the view instance back to
        // the caller for rendering or performing other view manipulations on this.
        $data = array_merge($mergeData, $this->parseData($data));

        return tap($this->viewInstance($view, $path, $data), function ($view) {
            $this->callCreator($view);
        });
    }

    /**
     * Get the first view that actually exists from the given list.
     *
     * @throws InvalidArgumentException
     */
    public function first(array $views, Arrayable|array $data = [], array $mergeData = []): ViewContract
    {
        $view = Arr::first($views, function ($view) {
            return $this->exists($view);
        });

        if (! $view) {
            throw new InvalidArgumentException('None of the views in the given array exist.');
        }

        return $this->make($view, $data, $mergeData);
    }

    /**
     * Get the rendered content of the view based on a given condition.
     */
    public function renderWhen(bool $condition, string $view, Arrayable|array $data = [], array $mergeData = []): string
    {
        if (! $condition) {
            return '';
        }

        return $this->make($view, $this->parseData($data), $mergeData)->render();
    }

    /**
     * Get the rendered content of the view based on the negation of a given condition.
     */
    public function renderUnless(bool $condition, string $view, Arrayable|array $data = [], array $mergeData = []): string
    {
        return $this->renderWhen(! $condition, $view, $data, $mergeData);
    }

    /**
     * Get the rendered contents of a partial from a loop.
     */
    public function renderEach(string $view, array $data, string $iterator, string $empty = 'raw|'): string
    {
        $result = '';

        // If is actually data in the array, we will loop through the data and append
        // an instance of the partial view to the final result HTML passing in the
        // iterated value of this data array, allowing the views to access them.
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $result .= $this->make(
                    $view,
                    ['key' => $key, $iterator => $value]
                )->render();
            }
        }

        // If there is no data in the array, we will render the contents of the empty
        // view. Alternatively, the "empty view" could be a raw string that begins
        // with "raw|" for convenience and to let this know that it is a string.
        else {
            $result = str_starts_with($empty, 'raw|')
                        ? substr($empty, 4)
                        : $this->make($empty)->render();
        }

        return $result;
    }

    /**
     * Normalize a view name.
     */
    protected function normalizeName(string $name): string
    {
        return $this->normalizedNameCache[$name] ??= ViewName::normalize($name);
    }

    /**
     * Parse the given data into a raw array.
     */
    protected function parseData(mixed $data): array
    {
        return $data instanceof Arrayable ? $data->toArray() : $data;
    }

    /**
     * Create a new view instance from the given arguments.
     */
    protected function viewInstance(string $view, string $path, Arrayable|array $data): ViewContract
    {
        return new View($this, $this->getEngineFromPath($path), $view, $path, $data);
    }

    /**
     * Determine if a given view exists.
     */
    public function exists(string $view): bool
    {
        try {
            $this->finder->find($view);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * Get the appropriate view engine for the given path.
     *
     * @throws InvalidArgumentException
     */
    public function getEngineFromPath(string $path): Engine
    {
        if (isset($this->pathEngineCache[$path])) {
            return $this->engines->resolve($this->pathEngineCache[$path]);
        }

        if (! $extension = $this->getExtension($path)) {
            throw new InvalidArgumentException("Unrecognized extension in file: {$path}.");
        }

        return $this->engines->resolve(
            $this->pathEngineCache[$path] = $this->extensions[$extension]
        );
    }

    /**
     * Get the extension used by the view file.
     */
    protected function getExtension(string $path): ?string
    {
        $extensions = array_keys($this->extensions);

        return Arr::first($extensions, function ($value) use ($path) {
            return str_ends_with($path, '.' . $value);
        });
    }

    /**
     * Add a piece of shared data to the environment.
     */
    public function share(array|string $key, mixed $value = null): mixed
    {
        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $key => $value) {
            $this->shared[$key] = $value;
        }

        return $value;
    }

    /**
     * Increment the rendering counter.
     */
    public function incrementRender(): void
    {
        Context::override(self::RENDER_COUNT_CONTEXT_KEY, function ($value) {
            return ($value ?? 0) + 1;
        });
    }

    /**
     * Decrement the rendering counter.
     */
    public function decrementRender(): void
    {
        Context::override(self::RENDER_COUNT_CONTEXT_KEY, function ($value) {
            return ($value ?? 1) - 1;
        });
    }

    /**
     * Get the rendering counter.
     */
    protected function getRenderCount(): int
    {
        return Context::get(self::RENDER_COUNT_CONTEXT_KEY, 0);
    }

    /**
     * Check if there are no active render operations.
     */
    public function doneRendering(): bool
    {
        return Context::get(self::RENDER_COUNT_CONTEXT_KEY, 0) === 0;
    }

    /**
     * Determine if the given once token has been rendered.
     */
    public function hasRenderedOnce(string $id): bool
    {
        $renderedOnce = Context::get(self::RENDERED_ONCE_CONTEXT_KEY, []);

        return isset($renderedOnce[$id]);
    }

    /**
     * Mark the given once token as having been rendered.
     */
    public function markAsRenderedOnce(string $id): void
    {
        Context::override(self::RENDERED_ONCE_CONTEXT_KEY, function ($value) use ($id) {
            $value ??= [];
            $value[$id] = true;

            return $value;
        });
    }

    /**
     * Add a location to the array of view locations.
     */
    public function addLocation(string $location): void
    {
        $this->finder->addLocation($location);
    }

    /**
     * Prepend a location to the array of view locations.
     */
    public function prependLocation(string $location): void
    {
        $this->finder->prependLocation($location);
    }

    /**
     * Add a new namespace to the loader.
     */
    public function addNamespace(string $namespace, string|array $hints): static
    {
        $this->finder->addNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Prepend a new namespace to the loader.
     */
    public function prependNamespace(string $namespace, string|array $hints): static
    {
        $this->finder->prependNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Replace the namespace hints for the given namespace.
     */
    public function replaceNamespace(string $namespace, string|array $hints): static
    {
        $this->finder->replaceNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Register a valid view extension and its engine.
     */
    public function addExtension(string $extension, string $engine, ?Closure $resolver = null): void
    {
        $this->finder->addExtension($extension);

        if (isset($resolver)) {
            $this->engines->register($engine, $resolver);
        }

        unset($this->extensions[$extension]);

        $this->extensions = array_merge([$extension => $engine], $this->extensions);

        $this->pathEngineCache = [];
    }

    /**
     * Flush all of the factory state like sections and stacks.
     */
    public function flushState(): void
    {
        Context::set(self::RENDER_COUNT_CONTEXT_KEY, 0);
        Context::set(self::RENDERED_ONCE_CONTEXT_KEY, []);

        $this->flushSections();
        $this->flushStacks();
        $this->flushComponents();
        $this->flushFragments();
    }

    /**
     * Flush all of the section contents if done rendering.
     */
    public function flushStateIfDoneRendering(): void
    {
        if ($this->doneRendering()) {
            $this->flushState();
        }
    }

    /**
     * Get the extension to engine bindings.
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Get the engine resolver instance.
     */
    public function getEngineResolver(): EngineResolver
    {
        return $this->engines;
    }

    /**
     * Get the view finder instance.
     */
    public function getFinder(): ViewFinderInterface
    {
        return $this->finder;
    }

    /**
     * Set the view finder instance.
     */
    public function setFinder(ViewFinderInterface $finder): void
    {
        $this->finder = $finder;
    }

    /**
     * Flush the cache of views located by the finder.
     */
    public function flushFinderCache(): void
    {
        $this->getFinder()->flush();
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setDispatcher(Dispatcher $events): void
    {
        $this->events = $events;
    }

    /**
     * Get the IoC container instance.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Get an item from the shared data.
     */
    public function shared(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->shared, $key, $default);
    }

    /**
     * Get all of the shared data for the environment.
     *
     * @return array
     */
    public function getShared()
    {
        return $this->shared;
    }
}
