<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Storage;

use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Json;
use Hypervel\Telescope\Contracts\ClearableRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\PrunableRepository;
use Hypervel\Telescope\Contracts\TerminableRepository;
use Hypervel\Telescope\EntryResult;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\EntryUpdate;
use Hypervel\Telescope\IncomingEntry;
use JsonException;
use Throwable;

class DatabaseEntriesRepository implements EntriesRepository, ClearableRepository, PrunableRepository, TerminableRepository
{
    /**
     * Context key for the per-request monitored tags cache.
     */
    protected const string MONITORED_TAGS_CONTEXT_KEY = '__telescope.monitored_tags';

    /**
     * The database connection name that should be used.
     */
    protected string $connection;

    /**
     * The number of entries that will be inserted at once into the database.
     */
    protected int $chunkSize = 1000;

    /**
     * Create a new database repository.
     */
    public function __construct(string $connection, ?int $chunkSize = null)
    {
        $this->connection = $connection;

        if ($chunkSize) {
            $this->chunkSize = $chunkSize;
        }
    }

    /**
     * Find the entry with the given ID.
     */
    public function find(mixed $id): EntryResult
    {
        $entry = EntryModel::on($this->connection)->whereUuid($id)->firstOrFail(); // @phpstan-ignore method.notFound

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
        return EntryModel::on($this->connection)
            ->withTelescopeOptions($type, $options) // @phpstan-ignore method.notFound (scope method registered at runtime)
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
                /** @var array $content */
                $content = $entry->content;
                $entry->content = $this->encodeContent($content);

                return $entry->toArray();
            })->toArray());
        });

        $this->storeTags($entries->pluck('tags', 'uuid'));
    }

    /**
     * Encode entry content for storage.
     */
    protected function encodeContent(array $content): string
    {
        try {
            return Json::encode($content, JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $exception) {
            if ($exception->getCode() !== JSON_ERROR_DEPTH) {
                throw $exception;
            }
        }

        // A one-key wrapper has the same root depth as the field in the full content array.
        foreach ($content as $key => $value) {
            try {
                Json::encode([$key => $value], JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (JsonException $exception) {
                if ($exception->getCode() !== JSON_ERROR_DEPTH) {
                    throw $exception;
                }

                $content[$key] = 'Purged By Telescope';
            }
        }

        return Json::encode($content, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Store the given array of exception entries.
     */
    protected function storeExceptions(Collection $exceptions): void
    {
        $exceptions->chunk($this->chunkSize)->each(function ($chunked) {
            $occurrences = [];
            $lastUuids = [];

            $families = $chunked->groupBy(fn ($exception) => $exception->familyHash())
                ->sortKeys();

            $families
                ->each(function ($family, $familyHash) use (&$occurrences, &$lastUuids): void {
                    $occurrences[$familyHash] = $this->countExceptionOccurences($family->first());
                    $lastUuids[$familyHash] = $family->last()->uuid;
                });

            $rows = $chunked->map(function ($exception) use (&$occurrences, $lastUuids) {
                $familyHash = $exception->familyHash();
                ++$occurrences[$familyHash];

                return array_merge($exception->toArray(), [
                    'family_hash' => $familyHash,
                    'should_display_on_index' => $exception->uuid === $lastUuids[$familyHash],
                    'content' => $this->encodeContent(
                        array_merge($exception->content, ['occurrences' => $occurrences[$familyHash]])
                    ),
                ]);
            })->toArray();

            $connection = DB::connection($this->connection);

            $connection->transaction(function () use ($connection, $families, $rows): void {
                $families->each(function ($family, $familyHash) use ($connection): void {
                    $connection->table('telescope_entries')
                        ->where('type', EntryType::EXCEPTION)
                        ->where('family_hash', $familyHash)
                        ->where('should_display_on_index', true)
                        ->update(['should_display_on_index' => false]);
                });

                $connection->table('telescope_entries')->insert($rows);
            });
        });

        $this->storeTags($exceptions->pluck('tags', 'uuid'));
    }

    /**
     * Store the tags for the given entries.
     */
    protected function storeTags(Collection $results): void
    {
        $toInsert = [];

        foreach ($results as $uuid => $tags) {
            foreach ($tags as $tag) {
                $toInsert[] = [
                    'entry_uuid' => $uuid,
                    'tag' => $tag,
                ];

                if (count($toInsert) >= $this->chunkSize) {
                    $this->insertChunkOfTags($toInsert);
                    $toInsert = [];
                }
            }
        }

        if ($toInsert !== []) {
            $this->insertChunkOfTags($toInsert);
        }
    }

    /**
     * Insert a chunk of tags, ignoring unique constraint violations.
     */
    protected function insertChunkOfTags(array $tags): void
    {
        try {
            $this->table('telescope_entries_tags')->insert($tags);
        } catch (UniqueConstraintViolationException $e) {
            // Ignore tags that already exist...
        }
    }

    /**
     * Store the given entry updates and return the failed updates.
     */
    public function update(Collection $updates): Collection
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

            $content = $this->encodeContent(
                array_merge(Json::decode($entry->content), $update->changes)
            );

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
        return CoroutineContext::get(self::MONITORED_TAGS_CONTEXT_KEY, null);
    }

    /**
     * Set the tags that should be monitored.
     */
    public function setMonitorTags(?array $tags): void
    {
        CoroutineContext::set(self::MONITORED_TAGS_CONTEXT_KEY, $tags);
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
        $tags = array_values(array_diff(array_unique($tags), $this->monitoring()));

        if (empty($tags)) {
            return;
        }

        $this->table('telescope_monitoring')->insert(
            array_map(static fn (string $tag): array => ['tag' => $tag], $tags),
        );
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
            ->where('created_at', '<', $before)
            ->orderBy('sequence');

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
            $deleted = $this->table('telescope_entries')->orderBy('sequence')->take($this->chunkSize)->delete();
        } while ($deleted !== 0);

        do {
            $deleted = $this->table('telescope_monitoring')->orderBy('tag')->take($this->chunkSize)->delete();
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
        return DB::connection($this->connection)->table($table);
    }
}
