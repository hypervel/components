<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\View\Factory;

class ViewWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
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
