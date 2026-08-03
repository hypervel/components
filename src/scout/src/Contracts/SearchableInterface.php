<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Closure;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engines\Engine;

/**
 * Internal shape for models that can be indexed and searched.
 *
 * Application models gain this capability through the Searchable trait and do
 * not need to implement this interface directly. Keep this shape compatible
 * with the trait while widening collection boundaries to Model for engines.
 *
 * @phpstan-require-extends \Hypervel\Database\Eloquent\Model
 */
interface SearchableInterface
{
    /**
     * Perform a search against the model's indexed data.
     *
     * @return Builder<Model&static>
     */
    public static function search(string $query = '', ?Closure $callback = null): Builder;

    /**
     * Get the requested models from an array of object IDs.
     *
     * @param array<int|string> $ids
     * @return Collection<int, Model&static>
     */
    public function getScoutModelsByIds(Builder $builder, array $ids): Collection;

    /**
     * Get a query builder for retrieving the requested models from an array of object IDs.
     *
     * @param array<int|string> $ids
     * @return EloquentBuilder<Model&static>
     */
    public function queryScoutModelsByIds(Builder $builder, array $ids): EloquentBuilder;

    /**
     * Modify the collection of models being made searchable.
     *
     * @param Collection<int, Model> $models
     * @return Collection<int, Model>
     */
    public function makeSearchableUsing(Collection $models): Collection;

    /**
     * Sync the soft deleted status for this model into the metadata.
     *
     * @return $this
     */
    public function pushSoftDeleteMetadata(): static;

    /**
     * Get the Scout engine for the model.
     */
    public function searchableUsing(): Engine;

    /**
     * Get the queue connection that should be used when syncing.
     */
    public function syncWithSearchUsing(): ?string;

    /**
     * Get the queue that should be used with syncing.
     */
    public function syncWithSearchUsingQueue(): ?string;

    /**
     * Make the given model instance searchable.
     */
    public function searchable(): void;

    /**
     * Remove the given model instance from the search index.
     */
    public function unsearchable(): void;

    /**
     * Get the index name for the model when searching.
     */
    public function searchableAs(): string;

    /**
     * Get the index name for the model when indexing.
     */
    public function indexableAs(): string;

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array;

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool;

    /**
     * Determine if the model's search index should be updated.
     */
    public function searchIndexShouldBeUpdated(): bool;

    /**
     * Determine if the model existed in the search index prior to an update.
     */
    public function wasSearchableBeforeUpdate(): bool;

    /**
     * Determine if the model existed in the search index prior to deletion.
     */
    public function wasSearchableBeforeDelete(): bool;

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed;

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string;

    /**
     * Get the auto-incrementing key type for querying models.
     */
    public function getScoutKeyType(): string;

    /**
     * Get all Scout related metadata.
     */
    public function scoutMetadata(): array;

    /**
     * Set a Scout related metadata.
     *
     * @return $this
     */
    public function withScoutMetadata(string $key, mixed $value): static;
}
