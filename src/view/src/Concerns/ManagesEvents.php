<?php

declare(strict_types=1);

namespace Hypervel\View\Concerns;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Support\Str;
use Hypervel\View\Contracts\View as ViewContract;

trait ManagesEvents
{
    /**
     * Indicates if view event handling is enabled.
     */
    protected bool $eventEnabled;

    /**
     * Register a view creator event.
     */
    public function creator(array|string $views, Closure|string $callback): array
    {
        $creators = [];

        foreach ((array) $views as $view) {
            $creators[] = $this->addViewEvent($view, $callback, 'creating: ');
        }

        return $creators;
    }

    /**
     * Register multiple view composers via an array.
     */
    public function composers(array $composers): array
    {
        $registered = [];

        foreach ($composers as $callback => $views) {
            $registered = array_merge($registered, $this->composer($views, $callback));
        }

        return $registered;
    }

    /**
     * Register a view composer event.
     */
    public function composer(array|string $views, Closure|string $callback): array
    {
        $composers = [];

        foreach ((array) $views as $view) {
            $composers[] = $this->addViewEvent($view, $callback);
        }

        return $composers;
    }

    /**
     * Add an event for a given view.
     */
    protected function addViewEvent(string $view, Closure|string $callback, string $prefix = 'composing: '): ?Closure
    {
        $view = $this->normalizeName($view);

        if ($callback instanceof Closure) {
            $this->addEventListener($prefix . $view, $callback);

            return $callback;
        }

        return $this->addClassEvent($view, $callback, $prefix);
    }

    /**
     * Register a class based view composer.
     */
    protected function addClassEvent(string $view, string $class, string $prefix): Closure
    {
        $name = $prefix . $view;

        // When registering a class based view "composer", we will simply resolve the
        // classes from the application IoC container then call the compose method
        // on the instance. This allows for convenient, testable view composers.
        $callback = $this->buildClassEventCallback(
            $class,
            $prefix
        );

        $this->addEventListener($name, $callback);

        return $callback;
    }

    /**
     * Build a class based container callback Closure.
     */
    protected function buildClassEventCallback(string $class, string $prefix): Closure
    {
        [$class, $method] = $this->parseClassEvent($class, $prefix);

        // Once we have the class and method name, we can build the Closure to resolve
        // the instance out of the IoC container and call the method on it with the
        // given arguments that are passed to the Closure as the composer's data.
        return function () use ($class, $method) {
            return $this->container->get($class)->{$method}(...func_get_args());
        };
    }

    /**
     * Parse a class based composer name.
     */
    protected function parseClassEvent(string $class, string $prefix): array
    {
        return Str::parseCallback($class, $this->classEventMethodForPrefix($prefix));
    }

    /**
     * Determine the class event method based on the given prefix.
     */
    protected function classEventMethodForPrefix(string $prefix): string
    {
        return str_contains($prefix, 'composing') ? 'compose' : 'create';
    }

    /**
     * Add a listener to the event dispatcher.
     */
    protected function addEventListener(string $name, Closure $callback): void
    {
        if (str_contains($name, '*')) {
            $callback = function ($name, array $data) use ($callback) {
                return $callback($data[0]);
            };
        }

        $this->events->listen($name, $callback);
    }

    /**
     * Call the composer for a given view.
     */
    public function callComposer(ViewContract $view): void
    {
        if ($this->isEventEnabled() && $this->events->hasListeners($event = 'composing: ' . $view->name())) {
            $this->events->dispatch($event, [$view]);
        }
    }

    protected function isEventEnabled(): bool
    {
        if (isset($this->eventEnabled)) {
            return $this->eventEnabled;
        }

        return $this->eventEnabled = $this->getContainer()->get(ConfigInterface::class)->get('view.event.enable', false);
    }

    /**
     * Call the creator for a given view.
     */
    public function callCreator(ViewContract $view): void
    {
        if ($this->getContainer()->get(ConfigInterface::class)->get('view.event.enable', false)
            && $this->events->hasListeners($event = 'creating: ' . $view->name())
        ) {
            $this->events->dispatch($event, [$view]);
        }
    }
}
