<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Contracts\Container\Container;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Hypervel\Contracts\Event\Dispatcher;

class BatchWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Container $app): void
    {
        $app->get(Dispatcher::class)
            ->listen(BatchDispatched::class, [$this, 'recordBatch']);
    }

    /**
     * Record a job being created.
     */
    public function recordBatch(BatchDispatched $event): ?IncomingEntry
    {
        if (! Telescope::isRecording()) {
            return null;
        }

        $content = array_merge($event->batch->toArray(), [
            'queue' => $event->batch->options['queue'] ?? 'default',
            'connection' => $event->batch->options['connection'] ?? 'default',
            'allowsFailures' => $event->batch->allowsFailures(),
        ]);

        Telescope::recordBatch(
            $entry = IncomingEntry::make(
                $content,
                $event->batch->id
            )->withFamilyHash($event->batch->id)
        );

        return $entry;
    }
}
