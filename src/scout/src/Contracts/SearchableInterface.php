<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engine;

/**
 * Contract for models that can be indexed and searched.
 *
 * This interface defines the public API that searchable models must implement.
 * The Searchable trait provides a default implementation of these methods.
 */
interface SearchableInterface
{
    /**
     * Perform a search against the model's indexed data.
     *
     * @return Builder<static>
     */
    public static function search(string $query = '', ?callable $callback = null): Builder;

    /**
     * Get the requested models from an array of object IDs.
     */
    public function getScoutModelsByIds(Builder $builder, array $ids): Collection;

    /**
     * Get the Scout engine for the model.
     */
    public function searchableUsing(): Engine;

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
