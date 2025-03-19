<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hyperf\ViewEngine\Contract\ViewInterface;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\Watchers\Traits\FormatsClosure;
use Hypervel\View\Events\ViewRendered;
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
            ->listen(ViewRendered::class, [$this, 'recordAction']);
    }

    /**
     * Record an action.
     */
    public function recordAction(ViewRendered $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        Telescope::recordView(IncomingEntry::make(array_filter([
            'name' => $event->view->name(),
            'path' => $this->extractPath($event->view),
            'data' => $this->extractKeysFromData($event->view),
            'composers' => [],
        ])));
    }

    /**
     * Extract the path from the given view.
     */
    protected function extractPath(ViewInterface $view): string
    {
        /** @var \Hyperf\ViewEngine\View $view */
        $path = $view->getPath();

        if (Str::startsWith($path, base_path())) {
            $path = substr($path, strlen(base_path()));
        }

        return $path;
    }

    /**
     * Extract the keys from the given view in array form.
     */
    protected function extractKeysFromData(ViewInterface $view): array
    {
        return Collection::make($view->getData())->filter(function ($value, $key) {
            return ! in_array($key, ['app', '__env', 'obLevel', 'errors']);
        })->keys()->toArray();
    }
}
