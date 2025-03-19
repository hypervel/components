<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Model;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class ModelWatcher extends Watcher
{
    public const HYDRATIONS = 'telescope.watcher.model.hydrations';

    public const MODEL_EVENTS = [
        \Hyperf\Database\Model\Events\Created::class,
        \Hyperf\Database\Model\Events\Deleted::class,
        \Hyperf\Database\Model\Events\ForceDeleted::class,
        \Hyperf\Database\Model\Events\Restored::class,
        \Hyperf\Database\Model\Events\Retrieved::class,
        \Hyperf\Database\Model\Events\Updated::class,
    ];

    /**
     * Telescope entries to store the count model hydrations.
     */
    public array $hydrationEntries = [];

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
            ->listen($this->options['events'] ?? static::MODEL_EVENTS, [$this, 'recordAction']);

        Telescope::afterStoring(function () {
            $this->flushHydrations();
        });
    }

    /**
     * Record an action.
     */
    public function recordAction(Event $event): void
    {
        $eventMethod = $event->getMethod();
        if (! Telescope::isRecording() || ! $this->shouldRecord($event)) {
            return;
        }

        $model = $event->getModel();
        if ($eventMethod === 'retrieved') {
            $this->recordHydrations($model);

            return;
        }

        $modelClass = FormatModel::given($event->getModel());

        $changes = $event->getModel()->getChanges();

        Telescope::recordModelEvent(IncomingEntry::make(array_filter([
            'action' => $eventMethod,
            'model' => $modelClass,
            'changes' => empty($changes) ? null : $changes,
        ]))->tags([$modelClass]));
    }

    public function getHyDrations(): array
    {
        return Context::get(static::HYDRATIONS, []);
    }

    public function getHydration(string $modelClass): ?IncomingEntry
    {
        return $this->getHyDrations()[$modelClass] ?? null;
    }

    public function updateHydration(string $modelClass, IncomingEntry $entry): void
    {
        Context::override(static::HYDRATIONS, function ($hydrations) use ($modelClass, $entry) {
            $hydrations = $hydrations ?? [];
            $hydrations[$modelClass] = $entry;

            return $hydrations;
        });
    }

    /**
     * Record model hydrations.
     */
    public function recordHydrations(Model $data): void
    {
        if (! ($this->options['hydrations'] ?? false)
            || ! $this->shouldRecordHydration($modelClass = get_class($data))
        ) {
            return;
        }

        if (! $entry = $this->getHyDration($modelClass)) {
            $this->updateHydration(
                $modelClass,
                IncomingEntry::make([
                    'action' => 'retrieved',
                    'model' => $modelClass,
                    'count' => 1,
                ])->tags([$modelClass])
            );

            Telescope::recordModelEvent($this->getHyDration($modelClass));
        } else {
            if (is_string($entry->content)) {
                $entry->content = json_decode($entry->content, true);
            }

            ++$entry->content['count'];
            $this->updateHydration($modelClass, $entry);
        }
    }

    /**
     * Flush the cached entries.
     */
    public function flushHydrations(): void
    {
        Context::set(static::HYDRATIONS, []);
    }

    /**
     * Determine if the Eloquent event should be recorded.
     */
    private function shouldRecord(Event $event): bool
    {
        return in_array(get_class($event), static::MODEL_EVENTS);
    }

    /**
     * Determine if the hydration should be recorded for the model class.
     */
    private function shouldRecordHydration(string $modelClass): bool
    {
        return Collection::make($this->options['ignore'] ?? [EntryModel::class])
            ->every(function ($class) use ($modelClass) {
                return $modelClass !== $class && ! is_subclass_of($modelClass, $class);
            });
    }
}
