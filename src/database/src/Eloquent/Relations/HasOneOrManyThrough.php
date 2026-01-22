<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\Contracts\Arrayable;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 * @template TResult
 *
 * @extends \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, TIntermediateModel, TResult>
 */
abstract class HasOneOrManyThrough extends Relation
{
    use InteractsWithDictionary;

    /**
     * The "through" parent model instance.
     *
     * @var TIntermediateModel
     */
    protected Model $throughParent;

    /**
     * The far parent model instance.
     *
     * @var TDeclaringModel
     */
    protected Model $farParent;

    /**
     * The near key on the relationship.
     */
    protected string $firstKey;

    /**
     * The far key on the relationship.
     */
    protected string $secondKey;

    /**
     * The local key on the relationship.
     */
    protected string $localKey;

    /**
     * The local key on the intermediary model.
     */
    protected string $secondLocalKey;

    /**
     * Create a new has many through relationship instance.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $farParent
     * @param  TIntermediateModel  $throughParent
     */
    public function __construct(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey)
    {
        $this->localKey = $localKey;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->farParent = $farParent;
        $this->throughParent = $throughParent;
        $this->secondLocalKey = $secondLocalKey;

        parent::__construct($query, $throughParent);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        $query = $this->getRelationQuery();

        $localValue = $this->farParent[$this->localKey];

        $this->performJoin($query);

        if (static::shouldAddConstraints()) {
            $query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }

    /**
     * Set the join clause on the query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>|null  $query
     */
    protected function performJoin(?Builder $query = null): void
    {
        $query ??= $this->query;

        $farKey = $this->getQualifiedFarKeyName();

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $farKey);

        if ($this->throughParentSoftDeletes()) {
            $query->withGlobalScope('SoftDeletableHasManyThrough', function ($query) {
                $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
            });
        }
    }

    /**
     * Get the fully qualified parent key name.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->secondLocalKey);
    }

    /**
     * Determine whether "through" parent of the relation uses Soft Deletes.
     */
    public function throughParentSoftDeletes(): bool
    {
        return $this->throughParent::isSoftDeletable();
    }

    /**
     * Indicate that trashed "through" parents should be included in the query.
     *
     * @return $this
     */
    public function withTrashedParents(): static
    {
        $this->query->withoutGlobalScope('SoftDeletableHasManyThrough');

        return $this;
    }

    /** @inheritDoc */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->farParent, $this->localKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedFirstKeyName(),
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery(),
        );
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>  $results
     * @return array<array<TRelatedModel>>
     */
    protected function buildDictionary(EloquentCollection $results): array
    {
        $dictionary = [];

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $result) {
            // @phpstan-ignore property.notFound (laravel_through_key is a select alias added during query)
            $dictionary[$result->laravel_through_key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @return TRelatedModel
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (! is_null($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return $this->related->newInstance(array_merge($attributes, $values));
    }

    /**
     * Get the first record matching the attributes. If the record is not found, create it.
     *
     * @return TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        if (! is_null($instance = (clone $this)->where($attributes)->first())) {
            return $instance;
        }

        return $this->createOrFirst(array_merge($attributes, $values));
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     *
     * @return TRelatedModel
     */
    public function createOrFirst(array $attributes = [], array $values = []): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, $values)));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->where($attributes)->first() ?? throw $exception;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @return TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return tap($this->firstOrCreate($attributes, $values), function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @param  \Closure|string|array  $column
     * @return TRelatedModel|null
     */
    public function firstWhere(Closure|string|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): ?Model
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first related model.
     *
     * @return TRelatedModel|null
     */
    public function first(array $columns = ['*']): ?Model
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return TRelatedModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     */
    public function firstOrFail(array $columns = ['*']): Model
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->related));
    }

    /**
     * Execute the query and get the first result or call a callback.
     *
     * @template TValue
     *
     * @param  (\Closure(): TValue)|list<string>  $columns
     * @param  (\Closure(): TValue)|null  $callback
     * @return TRelatedModel|TValue
     */
    public function firstOr(Closure|array $columns = ['*'], ?Closure $callback = null): mixed
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        return $callback();
    }

    /**
     * Find a related model by its primary key.
     *
     * @return ($id is (\Hypervel\Support\Contracts\Arrayable<array-key, mixed>|array<mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> : TRelatedModel|null)
     */
    public function find(mixed $id, array $columns = ['*']): EloquentCollection|Model|null
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), '=', $id
        )->first($columns);
    }

    /**
     * Find a sole related model by its primary key.
     *
     * @return TRelatedModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     * @throws \Hypervel\Database\MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*']): Model
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), '=', $id
        )->sole($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param  \Hypervel\Support\Contracts\Arrayable<array-key, mixed>|array<mixed>  $ids
     * @return \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>
     */
    public function findMany(Arrayable|array $ids, array $columns = ['*']): EloquentCollection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereIn(
            $this->getRelated()->getQualifiedKeyName(), $ids
        )->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @return ($id is (\Hypervel\Support\Contracts\Arrayable<array-key, mixed>|array<mixed>) ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> : TRelatedModel)
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     */
    public function findOrFail(mixed $id, array $columns = ['*']): EloquentCollection|Model
    {
        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (! is_null($result)) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->related), $id);
    }

    /**
     * Find a related model by its primary key or call a callback.
     *
     * @template TValue
     *
     * @param  (\Closure(): TValue)|list<string>|string  $columns
     * @param  (\Closure(): TValue)|null  $callback
     * @return (
     *     $id is (\Hypervel\Support\Contracts\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>|TValue
     *     : TRelatedModel|TValue
     * )
     */
    public function findOr(mixed $id, Closure|array|string $columns = ['*'], ?Closure $callback = null): mixed
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (! is_null($result)) {
            return $result;
        }

        return $callback();
    }

    /** @inheritDoc */
    public function get(array $columns = ['*']): EloquentCollection
    {
        $builder = $this->prepareQueryBuilder($columns);

        $models = $builder->getModels();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->query->applyAfterQueryCallbacks(
            $this->related->newCollection($models)
        );
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @return \Hypervel\Pagination\LengthAwarePaginator
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return \Hypervel\Pagination\Contracts\Paginator
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return $this->query->simplePaginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return \Hypervel\Pagination\Contracts\CursorPaginator
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return $this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Set the select clause for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns == ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName().' as laravel_through_key']);
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, $callback);
    }

    /**
     * Chunk the results of a query by comparing numeric IDs.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkByIdDesc($count, $callback, $column, $alias);
    }

    /**
     * Execute a callback over each item while chunking by ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        $column = $column ?? $this->getRelated()->getQualifiedKeyName();

        $alias = $alias ?? $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->eachById($callback, $count, $column, $alias);
    }

    /**
     * Get a generator for the given query.
     *
     * @return \Hypervel\Support\LazyCollection<int, TRelatedModel>
     */
    public function cursor(): mixed
    {
        return $this->prepareQueryBuilder()->cursor();
    }

    /**
     * Execute a callback over each item while chunking.
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }
        });
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @return \Hypervel\Support\LazyCollection<int, TRelatedModel>
     */
    public function lazy(int $chunkSize = 1000): mixed
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @return \Hypervel\Support\LazyCollection<int, TRelatedModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): mixed
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @return \Hypervel\Support\LazyCollection<int, TRelatedModel>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): mixed
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyByIdDesc($chunkSize, $column, $alias);
    }

    /**
     * Prepare the query builder for query execution.
     *
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(array $columns = ['*']): Builder
    {
        $builder = $this->query->applyScopes();

        return $builder->addSelect(
            $this->shouldSelect($builder->getQuery()->columns ? [] : $columns)
        );
    }

    /** @inheritDoc */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            // @phpstan-ignore argument.type (template types don't narrow through self-relation detection)
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        if ($parentQuery->getQuery()->from === $this->throughParent->getTable()) {
            // @phpstan-ignore argument.type (template types don't narrow through self-relation detection)
            return $this->getRelationExistenceQueryForThroughSelfRelation($query, $parentQuery, $columns);
        }

        // @phpstan-ignore argument.type (Builder<*> vs Builder<TRelatedModel>)
        $this->performJoin($query);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedLocalKeyName(), '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  \Hypervel\Database\Eloquent\Builder<TDeclaringModel>  $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $hash.'.'.$this->secondKey);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
        }

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from.'.'.$this->localKey, '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table as the through parent.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  \Hypervel\Database\Eloquent\Builder<TDeclaringModel>  $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForThroughSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $table = $this->throughParent->getTable().' as '.$hash = $this->getRelationCountHash();

        $query->join($table, $hash.'.'.$this->secondLocalKey, '=', $this->getQualifiedFarKeyName());

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($hash.'.'.$this->throughParent->getDeletedAtColumn());
        }

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from.'.'.$this->localKey, '=', $hash.'.'.$this->firstKey
        );
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @return $this
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @return $this
     */
    public function limit(int $value): static
    {
        if ($this->farParent->exists) {
            $this->query->limit($value);
        } else {
            $column = $this->getQualifiedFirstKeyName();

            $grammar = $this->query->getQuery()->getGrammar();

            if ($grammar instanceof MySqlGrammar && $grammar->useLegacyGroupLimit($this->query->getQuery())) {
                $column = 'laravel_through_key';
            }

            $this->query->groupLimit($value, $column);
        }

        return $this;
    }

    /**
     * Get the qualified foreign key on the related model.
     */
    public function getQualifiedFarKeyName(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the foreign key on the "through" model.
     */
    public function getFirstKeyName(): string
    {
        return $this->firstKey;
    }

    /**
     * Get the qualified foreign key on the "through" model.
     */
    public function getQualifiedFirstKeyName(): string
    {
        return $this->throughParent->qualifyColumn($this->firstKey);
    }

    /**
     * Get the foreign key on the related model.
     */
    public function getForeignKeyName(): string
    {
        return $this->secondKey;
    }

    /**
     * Get the qualified foreign key on the related model.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->qualifyColumn($this->secondKey);
    }

    /**
     * Get the local key on the far parent model.
     */
    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    /**
     * Get the qualified local key on the far parent model.
     */
    public function getQualifiedLocalKeyName(): string
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    /**
     * Get the local key on the intermediary model.
     */
    public function getSecondLocalKeyName(): string
    {
        return $this->secondLocalKey;
    }
}
