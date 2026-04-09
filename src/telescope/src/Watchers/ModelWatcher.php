<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;

class ModelWatcher extends Watcher
{
    public const HYDRATIONS_CONTEXT_KEY = '__telescope.watcher.model.hydrations';

    /**
     * Telescope entries to store the count model hydrations.
     */
    public array $hydrationEntries = [];

    /**
     * Register the watcher.
     */
    public function register(Container $app): void
    {
        $app->make(Dispatcher::class)
            ->observe($this->options['events'] ?? 'eloquent.*', [$this, 'recordAction']);

        Telescope::afterStoring(function () {
            $this->flushHydrations();
        });
    }

    /**
     * Record an action.
     *
     * @param string $event The event name (e.g., "eloquent.created: App\Models\User")
     * @param array $data The wildcard listener payload
     */
    public function recordAction(string $event, array $data): void
    {
        if (! isset($data[0]) || ! $data[0] instanceof Model) {
            return;
        }

        if (! Telescope::isRecording() || ! $this->shouldRecord($event)) {
            return;
        }

        if (Str::is('*retrieved*', $event)) {
            $this->recordHydrations($data[0]);

            return;
        }

        $modelClass = FormatModel::given($data[0]);

        $changes = $data[0]->getChanges();

        Telescope::recordModelEvent(IncomingEntry::make(array_filter([
            'action' => $this->action($event),
            'model' => $modelClass,
            'changes' => empty($changes) ? null : $changes,
        ]))->tags([$modelClass]));
    }

    /**
     * Extract the Eloquent action from the given event.
     */
    private function action(string $event): string
    {
        preg_match('/\.(.*):/', $event, $matches);

        return $matches[1];
    }

    /**
     * Get all hydration entries.
     */
    public function getHydrations(): array
    {
        return CoroutineContext::get(static::HYDRATIONS_CONTEXT_KEY, []);
    }

    /**
     * Get a hydration entry for the given model class.
     */
    public function getHydration(string $modelClass): ?IncomingEntry
    {
        return $this->getHydrations()[$modelClass] ?? null;
    }

    /**
     * Update the hydration entry for the given model class.
     */
    public function updateHydration(string $modelClass, IncomingEntry $entry): void
    {
        CoroutineContext::override(static::HYDRATIONS_CONTEXT_KEY, function ($hydrations) use ($modelClass, $entry) {
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

        if (! $entry = $this->getHydration($modelClass)) {
            $this->updateHydration(
                $modelClass,
                IncomingEntry::make([
                    'action' => 'retrieved',
                    'model' => $modelClass,
                    'count' => 1,
                ])->tags([$modelClass])
            );

            Telescope::recordModelEvent($this->getHydration($modelClass));
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
        CoroutineContext::set(static::HYDRATIONS_CONTEXT_KEY, []);
    }

    /**
     * Determine if the Eloquent event should be recorded.
     */
    private function shouldRecord(string $eventName): bool
    {
        return Str::is([
            '*created*', '*updated*', '*restored*', '*deleted*', '*retrieved*',
        ], $eventName);
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
