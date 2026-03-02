<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Telescope\FormatModel;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Telescope;

class ModelWatcher extends Watcher
{
    public const HYDRATIONS = 'telescope.watcher.model.hydrations';

    /**
     * The model events to watch.
     *
     * @var list<string>
     */
    public const MODEL_ACTIONS = [
        'created',
        'deleted',
        'forceDeleted',
        'restored',
        'retrieved',
        'updated',
    ];

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
            ->listen('eloquent.*', [$this, 'recordAction']);

        Telescope::afterStoring(function () {
            $this->flushHydrations();
        });
    }

    /**
     * Record an action.
     *
     * @param string $eventName The event name (e.g., "eloquent.created: App\Models\User")
     * @param array $payload The wildcard listener payload
     */
    public function recordAction(string $eventName, array $payload): void
    {
        if (! isset($payload[0]) || ! $payload[0] instanceof Model) {
            return;
        }

        $model = $payload[0];
        $action = $this->extractAction($eventName);

        if (! Telescope::isRecording() || ! $this->shouldRecord($action, $model)) {
            return;
        }

        if ($action === 'retrieved') {
            $this->recordHydrations($model);

            return;
        }

        $modelClass = FormatModel::given($model);

        $changes = $model->getChanges();

        Telescope::recordModelEvent(IncomingEntry::make(array_filter([
            'action' => $action,
            'model' => $modelClass,
            'changes' => empty($changes) ? null : $changes,
        ]))->tags([$modelClass]));
    }

    /**
     * Extract the action name from the event name.
     *
     * @param string $eventName Event name like "eloquent.created: App\Models\User"
     */
    protected function extractAction(string $eventName): string
    {
        // Extract "created" from "eloquent.created: App\Models\User"
        if (preg_match('/^eloquent\.([a-zA-Z]+):/', $eventName, $matches)) {
            return $matches[1];
        }

        return '';
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
    private function shouldRecord(string $action, Model $model): bool
    {
        if (! in_array($action, $this->options['actions'] ?? static::MODEL_ACTIONS)) {
            return false;
        }

        return Collection::make($this->options['ignore'] ?? [EntryModel::class])
            ->every(fn ($class) => ! $model instanceof $class);
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
