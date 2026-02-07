<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\Traits\FormatsClosure;
use Hypervel\View\Contracts\View as ViewContract;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class ViewWatcher extends Watcher
{
    use FormatsClosure;

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(ConfigInterface::class)
            ->set('view.event.enable', true);

        $app->get(EventDispatcherInterface::class)
            ->listen($this->options['events'] ?? 'composing:*', [$this, 'recordAction']);
    }

    /**
     * Record an action.
     */
    public function recordAction(string $event, ViewContract $view): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        Telescope::recordView(IncomingEntry::make(array_filter([
            'name' => $view->name(),
            'path' => $this->extractPath($view),
            'data' => $this->extractKeysFromData($view),
            'composers' => [],
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
            return ! in_array($key, ['app', '__env', 'obLevel', 'errors']);
        })->keys()->toArray();
    }
}
