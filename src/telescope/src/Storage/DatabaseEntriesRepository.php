<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Storage;

use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Exception\UniqueConstraintViolationException;
use Hyperf\Database\Query\Builder;
use Hypervel\Telescope\Contracts\ClearableRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\PrunableRepository;
use Hypervel\Telescope\Contracts\TerminableRepository;
use Hypervel\Telescope\EntryResult;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\EntryUpdate;
use Hypervel\Telescope\IncomingEntry;
use Throwable;

use function Hypervel\Config\config;

class DatabaseEntriesRepository implements EntriesRepository, ClearableRepository, PrunableRepository, TerminableRepository
{
    /**
     * Create a new database repository.
     *
     * @param ConnectionResolverInterface $resolver the database connection resolver instance
     * @param string $connection the database connection name that should be used
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected ?string $connection = null,
        protected ?int $chunkSize = null
    ) {
        $this->connection = $connection
            ?? config('telescope.storage.database.connection', null);
        $this->chunkSize = $chunkSize
            ?? config('telescope.storage.database.chunk', 1000);
    }

    /**
     * Find the entry with the given ID.
     */
    public function find(mixed $id): EntryResult
    {
        $entry = EntryModel::on($this->connection)
            ->where('uuid', $id)
            ->firstOrFail();

        $tags = $this->table('telescope_entries_tags')
            ->where('entry_uuid', $id)
            ->pluck('tag')
            ->all();

        return new EntryResult(
            $entry->uuid, // @phpstan-ignore-line
            null,
            $entry->batch_id, // @phpstan-ignore-line
            $entry->type, // @phpstan-ignore-line
            $entry->family_hash, // @phpstan-ignore-line
            $entry->content, // @phpstan-ignore-line
            $entry->created_at, // @phpstan-ignore-line
            $tags
        );
    }

    /**
     * Return all the entries of a given type.
     */
    public function get(?string $type, EntryQueryOptions $options): Collection
    {
        /* @phpstan-ignore-next-line */
        return EntryModel::on($this->connection)
            ->withTelescopeOptions($type, $options)
            ->take($options->limit)
            ->orderByDesc('sequence')
            ->get()->reject(function ($entry) {
                return ! is_array($entry->content);
            })->map(function ($entry) {
                return new EntryResult(
                    $entry->uuid,
                    $entry->sequence,
                    $entry->batch_id,
                    $entry->type,
                    $entry->family_hash,
                    $entry->content,
                    $entry->created_at,
                    []
                );
            })->values();
    }

    /**
     * Counts the occurences of an exception.
     */
    protected function countExceptionOccurences(IncomingEntry $exception): int
    {
        return $this->table('telescope_entries')
            ->where('type', EntryType::EXCEPTION)
            ->where('family_hash', $exception->familyHash())
            ->count();
    }

    /**
     * Store the given array of entries.
     */
    public function store(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        [$exceptions, $entries] = $entries->partition->isException();

        $this->storeExceptions($exceptions);

        $table = $this->table('telescope_entries');

        $entries->chunk($this->chunkSize)->each(function ($chunked) use ($table) {
            $table->insert($chunked->map(function ($entry) {
                $entry->content = json_encode($entry->content, JSON_INVALID_UTF8_SUBSTITUTE);

                return $entry->toArray();
            })->toArray());
        });

        $this->storeTags($entries->pluck('tags', 'uuid'));
    }

    /**
     * Store the given array of exception entries.
     */
    protected function storeExceptions(Collection $exceptions): void
    {
        $exceptions->chunk($this->chunkSize)->each(function ($chunked) {
            $this->table('telescope_entries')->insert($chunked->map(function ($exception) {
                $occurrences = $this->countExceptionOccurences($exception);

                $this->table('telescope_entries')
                    ->where('type', EntryType::EXCEPTION)
                    ->where('family_hash', $exception->familyHash())
                    ->update(['should_display_on_index' => false]);

                return array_merge($exception->toArray(), [
                    'family_hash' => $exception->familyHash(),
                    'content' => json_encode(array_merge(
                        $exception->content,
                        ['occurrences' => $occurrences + 1]
                    )),
                ]);
            })->toArray());
        });

        $this->storeTags($exceptions->pluck('tags', 'uuid'));
    }

    /**
     * Store the tags for the given entries.
     */
    protected function storeTags(Collection $results): void
    {
        $results->chunk($this->chunkSize)->each(function ($chunked) {
            try {
                $this->table('telescope_entries_tags')->insert($chunked->flatMap(function ($tags, $uuid) {
                    return Collection::make($tags)->map(function ($tag) use ($uuid) {
                        return [
                            'entry_uuid' => $uuid,
                            'tag' => $tag,
                        ];
                    });
                })->all());
            } catch (UniqueConstraintViolationException $e) {
                // Ignore tags that already exist...
            }
        });
    }

    /**
     * Store the given entry updates and return the failed updates.
     */
    public function update(Collection $updates): ?Collection
    {
        $failedUpdates = [];

        foreach ($updates as $update) {
            $entry = $this->table('telescope_entries')
                ->where('uuid', $update->uuid)
                ->where('type', $update->type)
                ->first();

            if (! $entry) {
                $failedUpdates[] = $update;

                continue;
            }

            $content = json_encode(array_merge(
                json_decode($entry->content ?? $entry['content'] ?? [], true) ?: [],
                $update->changes
            ));

            $this->table('telescope_entries')
                ->where('uuid', $update->uuid)
                ->where('type', $update->type)
                ->update(['content' => $content]);

            $this->updateTags($update);
        }

        return Collection::make($failedUpdates);
    }

    /**
     * Update tags of the given entry.
     */
    protected function updateTags(EntryUpdate $entry): void
    {
        if (! empty($entry->tagsChanges['added'])) {
            try {
                $this->table('telescope_entries_tags')->insert(
                    Collection::make($entry->tagsChanges['added'])->map(function ($tag) use ($entry) {
                        return [
                            'entry_uuid' => $entry->uuid,
                            'tag' => $tag,
                        ];
                    })->toArray()
                );
            } catch (UniqueConstraintViolationException $e) {
                // Ignore tags that already exist...
            }
        }

        Collection::make($entry->tagsChanges['removed'])->each(function ($tag) use ($entry) {
            $this->table('telescope_entries_tags')->where([
                'entry_uuid' => $entry->uuid,
                'tag' => $tag,
            ])->delete();
        });
    }

    /**
     * Get the tags that should be monitored.
     */
    public function getMonitorTags(): ?array
    {
        return Context::get('telescope.monitored_tags', null);
    }

    /**
     * Set the tags that should be monitored.
     */
    public function setMonitorTags(?array $tags): void
    {
        Context::set('telescope.monitored_tags', $tags);
    }

    /**
     * Load the monitored tags from storage.
     */
    public function loadMonitoredTags(): void
    {
        try {
            $this->setMonitorTags($this->monitoring());
        } catch (Throwable $e) {
            $this->setMonitorTags([]);
        }
    }

    /**
     * Determine if any of the given tags are currently being monitored.
     */
    public function isMonitoring(array $tags): bool
    {
        if (is_null($this->getMonitorTags())) {
            $this->loadMonitoredTags();
        }

        return count(array_intersect($tags, $this->getMonitorTags())) > 0;
    }

    /**
     * Get the list of tags currently being monitored.
     */
    public function monitoring(): array
    {
        return $this->table('telescope_monitoring')->pluck('tag')->all();
    }

    /**
     * Begin monitoring the given list of tags.
     */
    public function monitor(array $tags): void
    {
        $tags = array_diff($tags, $this->monitoring());

        if (empty($tags)) {
            return;
        }

        $this->table('telescope_monitoring')
            ->insert(Collection::make($tags)
                ->mapWithKeys(function ($tag) {
                    return ['tag' => $tag];
                })->all());
    }

    /**
     * Stop monitoring the given list of tags.
     */
    public function stopMonitoring(array $tags): void
    {
        $this->table('telescope_monitoring')
            ->whereIn('tag', $tags)
            ->delete();
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before, bool $keepExceptions): int
    {
        $query = $this->table('telescope_entries')
            ->where('created_at', '<', $before);

        if ($keepExceptions) {
            $query->where('type', '!=', 'exception');
        }

        $totalDeleted = 0;

        do {
            $deleted = $query->take($this->chunkSize)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Clear all the entries.
     */
    public function clear(): void
    {
        do {
            $deleted = $this->table('telescope_entries')->take($this->chunkSize)->delete();
        } while ($deleted !== 0);

        do {
            $deleted = $this->table('telescope_monitoring')->take($this->chunkSize)->delete();
        } while ($deleted !== 0);
    }

    /**
     * Perform any clean-up tasks needed after storing Telescope entries.
     */
    public function terminate(): void
    {
        $this->setMonitorTags(null);
    }

    /**
     * Get a query builder instance for the given table.
     */
    protected function table(string $table): Builder
    {
        return $this->resolver
            ->connection($this->connection)
            ->table($table);
    }
}
