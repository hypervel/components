<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\View\Factory;
use ReflectionFunction;

class ViewWatcher extends Watcher
{
    use FormatsClosure;

    /**
     * The event dispatcher.
     */
    protected Dispatcher $events;

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $this->events = $app->make(Dispatcher::class);

        $app->make(Factory::class)
            ->observeRendering([$this, 'recordRenderedView']);
    }

    /**
     * Record a rendered view.
     */
    public function recordRenderedView(ViewContract $view): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        Telescope::recordView(IncomingEntry::make(array_filter([
            'name' => $view->name(),
            'path' => $this->extractPath($view),
            'data' => $this->extractKeysFromData($view),
            'composers' => $this->formatComposers($view),
        ])));
    }

    /**
     * Extract the path from the given view.
     */
    protected function extractPath(ViewContract $view): string
    {
        $path = $view->getPath();

        if (Str::startsWith($path, base_path())) {
            $path = substr($path, strlen(base_path()));
        }

        return $path;
    }

    /**
     * Extract the keys from the given view in array form.
     */
    protected function extractKeysFromData(ViewContract $view): array
    {
        return Collection::make($view->getData())->filter(function ($value, $key) {
            return ! in_array($key, ['app', '__env', 'obLevel', 'errors'], true);
        })->keys()->toArray();
    }

    /**
     * Format the view's composers and creators.
     */
    protected function formatComposers(ViewContract $view): array
    {
        return Collection::make([
            'composing: ' . $view->name(),
            'creating: ' . $view->name(),
        ])->map(function ($event) {
            return $this->getComposersForEvent($event)
                ->map(function ($composer) use ($event) {
                    return [
                        'name' => $composer,
                        'type' => Str::startsWith($event, 'creating:') ? 'creator' : 'composer',
                    ];
                });
        })->collapse()->values()->toArray();
    }

    /**
     * Get all view composers for the given event.
     */
    protected function getComposersForEvent(string $eventName): Collection
    {
        return Collection::make($this->events->getListeners($eventName))
            ->map(function ($listener) {
                return (new ReflectionFunction($listener))->getStaticVariables();
            })->reject(function ($variables) {
                // The dispatcher wraps every listener in a Closure. Only wrappers that captured a
                // Closure hold composer metadata; string and array listeners hold none.
                return ! $variables['listener'] instanceof Closure;
            })->map(function ($variables) {
                $closure = new ReflectionFunction($listener = $variables['listener']);

                if ($this->isWildcardViewComposer($variables, $closure)) {
                    $closure = new ReflectionFunction($listener = $closure->getStaticVariables()['callback']);
                }

                if ($this->isViewComposerClosure($closure)) {
                    return $closure->getStaticVariables()['class'] . '@' . $closure->getStaticVariables()['method'];
                }

                return $this->formatClosureListener($listener);
            });
    }

    /**
     * Determine if the composer is wrapped for a wildcard event.
     */
    protected function isWildcardViewComposer(array $variables, ReflectionFunction $closure): bool
    {
        return $variables['wildcard'] && array_key_exists('callback', $closure->getStaticVariables());
    }

    /**
     * Determine if the closure is a class-based view composer.
     */
    protected function isViewComposerClosure(ReflectionFunction $closure): bool
    {
        $scope = $closure->getClosureScopeClass();

        return $scope !== null
            && $scope->implementsInterface(ViewFactoryContract::class)
            && array_key_exists('class', $closure->getStaticVariables());
    }
}
