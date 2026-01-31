<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\Relations\Concerns\AsPivot;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\StrCache;
use InvalidArgumentException;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 * @template TPivotModel of \Hypervel\Database\Eloquent\Relations\Pivot = \Hypervel\Database\Eloquent\Relations\Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, TDeclaringModel, \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel>>
 *
 * @todo use TAccessor when PHPStan bug is fixed: https://github.com/phpstan/phpstan/issues/12756
 */
class BelongsToMany extends Relation
{
    use InteractsWithDictionary;
    use InteractsWithPivotTable;

    /**
     * The intermediate table for the relation.
     */
    protected string $table;

    /**
     * The foreign key of the parent model.
     */
    protected string $foreignPivotKey;

    /**
     * The associated key of the relation.
     */
    protected string $relatedPivotKey;

    /**
     * The key name of the parent model.
     */
    protected string $parentKey;

    /**
     * The key name of the related model.
     */
    protected string $relatedKey;

    /**
     * The "name" of the relationship.
     */
    protected ?string $relationName;

    /**
     * The pivot table columns to retrieve.
     *
     * @var array<\Hypervel\Contracts\Database\Query\Expression|string>
     */
    protected array $pivotColumns = [];

    /**
     * Any pivot table restrictions for where clauses.
     */
    protected array $pivotWheres = [];

    /**
     * Any pivot table restrictions for whereIn clauses.
     */
    protected array $pivotWhereIns = [];

    /**
     * Any pivot table restrictions for whereNull clauses.
     */
    protected array $pivotWhereNulls = [];

    /**
     * The default values for the pivot columns.
     */
    protected array $pivotValues = [];

    /**
     * Indicates if timestamps are available on the pivot table.
     */
    public bool $withTimestamps = false;

    /**
     * The custom pivot table column for the created_at timestamp.
     */
    protected ?string $pivotCreatedAt = null;

    /**
     * The custom pivot table column for the updated_at timestamp.
     */
    protected ?string $pivotUpdatedAt = null;

    /**
     * The class name of the custom pivot model to use for the relationship.
     *
     * @var null|class-string<TPivotModel>
     */
    protected ?string $using = null;

    /**
     * The name of the accessor to use for the "pivot" relationship.
     *
     * @var TAccessor
     */
    protected string $accessor = 'pivot';

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     * @param class-string<TRelatedModel>|string $table
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
    ) {
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
        $this->relationName = $relationName;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->table = $this->resolveTableName($table);

        parent::__construct($query, $parent);
    }

    /**
     * Attempt to resolve the intermediate table name from the given string.
     */
    protected function resolveTableName(string $table): string
    {
        if (! str_contains($table, '\\') || ! class_exists($table)) {
            return $table;
        }

        $model = new $table();

        if (! $model instanceof Model) {
            return $table;
        }

        if (in_array(AsPivot::class, class_uses_recursive($model))) {
            $this->using($table);
        }

        return $model->getTable();
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        if (static::shouldAddConstraints()) {
            $this->addWhereConstraints();
        }
    }

    /**
     * Set the join clause for the relation query.
     *
     * @param null|\Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @return $this
     */
    protected function performJoin(?Builder $query = null): static
    {
        $query = $query ?: $this->query;

        // We need to join to the intermediate table on the related model's primary
        // key column with the intermediate table's foreign key for the related
        // model instance. Then we can set the "where" for the parent models.
        $query->join(
            $this->table,
            $this->getQualifiedRelatedKeyName(),
            '=',
            $this->getQualifiedRelatedPivotKeyName()
        );

        return $this;
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function addWhereConstraints(): static
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(),
            '=',
            $this->parent->{$this->parentKey}
        );

        return $this;
    }

    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey)
        );
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have an array dictionary of child objects we can easily match the
        // children back to their parent using the dictionary and the keys on the
        // parent models. Then we should return these hydrated models back out.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->{$this->parentKey});

            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> $results
     * @return array<list<TRelatedModel>>
     */
    protected function buildDictionary(EloquentCollection $results): array
    {
        // First we'll build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to the
        // parents without having a possibly slow inner loop for every model.
        $dictionary = [];

        foreach ($results as $result) {
            $value = $this->getDictionaryKey($result->{$this->accessor}->{$this->foreignPivotKey});

            $dictionary[$value][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the class being used for pivot models.
     *
     * @return class-string<TPivotModel>
     */
    public function getPivotClass(): string
    {
        return $this->using ?? Pivot::class;
    }

    /**
     * Specify the custom pivot model to use for the relationship.
     *
     * @template TNewPivotModel of \Hypervel\Database\Eloquent\Relations\Pivot
     *
     * @param class-string<TNewPivotModel> $class
     * @return $this
     *
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TNewPivotModel, TAccessor>
     */
    public function using(string $class): static
    {
        $this->using = $class;

        return $this;
    }

    /**
     * Specify the custom pivot accessor to use for the relationship.
     *
     * @template TNewAccessor of string
     *
     * @param TNewAccessor $accessor
     * @return $this
     *
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TPivotModel, TNewAccessor>
     */
    public function as(string $accessor): static
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivot(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        $this->pivotWheres[] = func_get_args();

        return $this->where($this->qualifyPivotColumn($column), $operator, $value, $boolean);
    }

    /**
     * Set a "where between" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotBetween(mixed $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereBetween($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set a "or where between" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orWherePivotBetween(mixed $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or');
    }

    /**
     * Set a "where pivot not between" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotNotBetween(mixed $column, array $values, string $boolean = 'and'): static
    {
        return $this->wherePivotBetween($column, $values, $boolean, true);
    }

    /**
     * Set a "or where not between" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orWherePivotNotBetween(mixed $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or', true);
    }

    /**
     * Set a "where in" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotIn(mixed $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereIns[] = func_get_args();

        return $this->whereIn($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set an "or where" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orWherePivot(mixed $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->wherePivot($column, $operator, $value, 'or');
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * In addition, new pivot records will receive this value.
     *
     * @param array<string, string>|\Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function withPivotValue(mixed $column, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $name => $value) {
                $this->withPivotValue($name, $value);
            }

            return $this;
        }

        if (is_null($value)) {
            throw new InvalidArgumentException('The provided value may not be null.');
        }

        $this->pivotValues[] = compact('column', 'value');

        return $this->wherePivot($column, '=', $value);
    }

    /**
     * Set an "or where in" clause for a pivot table column.
     *
     * @return $this
     */
    public function orWherePivotIn(string $column, mixed $values): static
    {
        return $this->wherePivotIn($column, $values, 'or');
    }

    /**
     * Set a "where not in" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotNotIn(mixed $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->wherePivotIn($column, $values, $boolean, true);
    }

    /**
     * Set an "or where not in" clause for a pivot table column.
     *
     * @return $this
     */
    public function orWherePivotNotIn(string $column, mixed $values): static
    {
        return $this->wherePivotNotIn($column, $values, 'or');
    }

    /**
     * Set a "where null" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotNull(mixed $column, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereNulls[] = func_get_args();

        return $this->whereNull($this->qualifyPivotColumn($column), $boolean, $not);
    }

    /**
     * Set a "where not null" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function wherePivotNotNull(mixed $column, string $boolean = 'and'): static
    {
        return $this->wherePivotNull($column, $boolean, true);
    }

    /**
     * Set a "or where null" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orWherePivotNull(mixed $column, bool $not = false): static
    {
        return $this->wherePivotNull($column, 'or', $not);
    }

    /**
     * Set a "or where not null" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orWherePivotNotNull(mixed $column): static
    {
        return $this->orWherePivotNull($column, true);
    }

    /**
     * Add an "order by" clause for a pivot table column.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return $this
     */
    public function orderByPivot(mixed $column, string $direction = 'asc'): static
    {
        return $this->orderBy($this->qualifyPivotColumn($column), $direction);
    }

    /**
     * Find a related model by its primary key or return a new instance of the related model.
     *
     * @return (
     *     $id is (\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
     */
    public function findOrNew(mixed $id, array $columns = ['*']): EloquentCollection|Model
    {
        if (is_null($instance = $this->find($id, $columns))) {
            $instance = $this->related->newInstance();
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (is_null($instance = $this->related->where($attributes)->first())) {
            $instance = $this->related->newInstance(array_merge($attributes, $values));
        }

        return $instance;
    }

    /**
     * Get the first record matching the attributes. If the record is not found, create it.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], array $values = [], array $joining = [], bool $touch = true): Model
    {
        if (is_null($instance = (clone $this)->where($attributes)->first())) {
            if (is_null($instance = $this->related->where($attributes)->first())) {
                $instance = $this->createOrFirst($attributes, $values, $joining, $touch);
            } else {
                try {
                    $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
                } catch (UniqueConstraintViolationException) {
                    // Nothing to do, the model was already attached...
                }
            }
        }

        return $instance;
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function createOrFirst(array $attributes = [], array $values = [], array $joining = [], bool $touch = true): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, $values), $joining, $touch));
        } catch (UniqueConstraintViolationException $e) {
            // ...
        }

        try {
            return tap($this->related->where($attributes)->first() ?? throw $e, function ($instance) use ($joining, $touch) {
                $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
            });
        } catch (UniqueConstraintViolationException $e) {
            return (clone $this)->useWritePdo()->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = [], array $joining = [], bool $touch = true): Model
    {
        return tap($this->firstOrCreate($attributes, $values, $joining, $touch), function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values);

                $instance->save(['touch' => false]);
            }
        });
    }

    /**
     * Find a related model by its primary key.
     *
     * @return (
     *     $id is (\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : (TRelatedModel&object{pivot: TPivotModel})|null
     * )
     */
    public function find(mixed $id, array $columns = ['*']): EloquentCollection|Model|null
    {
        if (! $id instanceof Model && (is_array($id) || $id instanceof Arrayable)) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(),
            '=',
            $this->parseId($id)
        )->first($columns);
    }

    /**
     * Find a sole related model by its primary key.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     * @throws \Hypervel\Database\MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*']): Model
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(),
            '=',
            $this->parseId($id)
        )->sole($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @param array<mixed>|\Hypervel\Contracts\Support\Arrayable<array-key, mixed> $ids
     * @return \Hypervel\Database\Eloquent\Collection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function findMany(Arrayable|array $ids, array $columns = ['*']): EloquentCollection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereKey(
            $this->parseIds($ids)
        )->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @return (
     *     $id is (\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
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

        throw (new ModelNotFoundException())->setModel(get_class($this->related), $id);
    }

    /**
     * Find a related model by its primary key or call a callback.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string>|string $columns
     * @param null|(Closure(): TValue) $callback
     * @return (
     *     $id is (\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|array<mixed>)
     *     ? \Hypervel\Database\Eloquent\Collection<int, TRelatedModel&object{pivot: TPivotModel}>|TValue
     *     : (TRelatedModel&object{pivot: TPivotModel})|TValue
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

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @return null|(object{pivot: TPivotModel}&TRelatedModel)
     */
    public function firstWhere(Closure|string|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): ?Model
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first result.
     *
     * @return null|(object{pivot: TPivotModel}&TRelatedModel)
     */
    public function first(array $columns = ['*']): ?Model
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     */
    public function firstOrFail(array $columns = ['*']): Model
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->related));
    }

    /**
     * Execute the query and get the first result or call a callback.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string> $columns
     * @param null|(Closure(): TValue) $callback
     * @return (object{pivot: TPivotModel}&TRelatedModel)|TValue
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

    public function getResults()
    {
        return ! is_null($this->parent->{$this->parentKey})
            ? $this->get()
            : $this->related->newCollection();
    }

    public function get(array $columns = ['*']): BaseCollection
    {
        // First we'll add the proper select columns onto the query so it is run with
        // the proper columns. Then, we will get the results and hydrate our pivot
        // models with the result of those columns as a separate model relation.
        $builder = $this->query->applyScopes();

        $columns = $builder->getQuery()->columns ? [] : $columns;

        // @phpstan-ignore method.notFound (addSelect returns Eloquent\Builder, not Query\Builder)
        $models = $builder->addSelect(
            $this->shouldSelect($columns)
        )->getModels();

        $this->hydratePivotRelation($models);

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
     * Get the select columns for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns == ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }

    /**
     * Get the pivot columns for the relation.
     *
     * "pivot_" is prefixed at each column for easy removal later.
     */
    protected function aliasedPivotColumns(): array
    {
        return (new BaseCollection([
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            ...$this->pivotColumns,
        ]))
            ->map(fn ($column) => $this->qualifyPivotColumn($column) . ' as pivot_' . $column)
            ->unique()
            ->all();
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @return \Hypervel\Pagination\LengthAwarePaginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return tap($this->query->paginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return \Hypervel\Contracts\Pagination\Paginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return tap($this->query->simplePaginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return \Hypervel\Contracts\Pagination\CursorPaginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): mixed
    {
        $this->query->addSelect($this->shouldSelect($columns));

        return tap($this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        });
    }

    /**
     * Chunk the results of a query by comparing numeric IDs.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: true);
    }

    /**
     * Execute a callback over each item while chunking by ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        return $this->chunkById($count, function ($results, $page) use ($callback, $count) {
            foreach ($results as $key => $value) {
                if ($callback($value, (($page - 1) * $count) + $key) === false) {
                    return false;
                }
            }
        }, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in a given order.
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->orderedChunkById($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        }, $column, $alias, $descending);
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
     * @return \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazy(int $chunkSize = 1000): mixed
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @return \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): mixed
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @return \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): mixed
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->lazyByIdDesc($chunkSize, $column, $alias)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return \Hypervel\Support\LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function cursor(): mixed
    {
        return $this->prepareQueryBuilder()->cursor()->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Prepare the query builder for query execution.
     *
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(): Builder
    {
        return $this->query->addSelect($this->shouldSelect());
    }

    /**
     * Hydrate the pivot table relationship on the models.
     *
     * @param array<int, TRelatedModel> $models
     */
    protected function hydratePivotRelation(array $models): void
    {
        // To hydrate the pivot relationship, we will just gather the pivot attributes
        // and create a new Pivot model, which is basically a dynamic model that we
        // will set the attributes, table, and connections on it so it will work.
        foreach ($models as $model) {
            $model->setRelation($this->accessor, $this->newExistingPivot(
                $this->migratePivotAttributes($model)
            ));
        }
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param TRelatedModel $model
     */
    protected function migratePivotAttributes(Model $model): array
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            // To get the pivots attributes we will just take any of the attributes which
            // begin with "pivot_" and add those to this arrays, as well as unsetting
            // them from the parent's models since they exist in a different table.
            if (str_starts_with($key, 'pivot_')) {
                $values[substr($key, 6)] = $value;

                unset($model->{$key});
            }
        }

        return $values;
    }

    /**
     * If we're touching the parent model, touch.
     */
    public function touchIfTouching(): void
    {
        if ($this->touchingParent()) {
            $this->getParent()->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Determine if we should touch the parent on sync.
     */
    protected function touchingParent(): bool
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Attempt to guess the name of the inverse of the relation.
     */
    protected function guessInverseRelation(): string
    {
        return StrCache::camel(StrCache::pluralStudly(class_basename($this->getParent())));
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * E.g.: Touch all roles associated with this user.
     */
    public function touch(): void
    {
        if ($this->related->isIgnoringTouch()) {
            return;
        }

        $columns = [
            $this->related->getUpdatedAtColumn() => $this->related->freshTimestampString(),
        ];

        // If we actually have IDs for the relation, we will run the query to update all
        // the related model's timestamps, to make sure these all reflect the changes
        // to the parent models. This will help us keep any caching synced up here.
        if (count($ids = $this->allRelatedIds()) > 0) {
            $this->getRelated()->newQueryWithoutRelationships()->whereKey($ids)->update($columns);
        }
    }

    /**
     * Get all of the IDs for the related models.
     *
     * @return \Hypervel\Support\Collection<int, int|string>
     */
    public function allRelatedIds(): BaseCollection
    {
        return $this->newPivotQuery()->pluck($this->relatedPivotKey);
    }

    /**
     * Save a new model and attach it to the parent model.
     *
     * @param TRelatedModel $model
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function save(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        $model->save(['touch' => false]);

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /**
     * Save a new model without raising any events and attach it to the parent model.
     *
     * @param TRelatedModel $model
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function saveQuietly(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        return Model::withoutEvents(function () use ($model, $pivotAttributes, $touch) {
            return $this->save($model, $pivotAttributes, $touch);
        });
    }

    /**
     * Save an array of new models and attach them to the parent model.
     *
     * @template TContainer of \Hypervel\Support\Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param TContainer $models
     * @return TContainer
     */
    public function saveMany(iterable $models, array $pivotAttributes = []): iterable
    {
        foreach ($models as $key => $model) {
            $this->save($model, (array) ($pivotAttributes[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $models;
    }

    /**
     * Save an array of new models without raising any events and attach them to the parent model.
     *
     * @template TContainer of \Hypervel\Support\Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param TContainer $models
     * @return TContainer
     */
    public function saveManyQuietly(iterable $models, array $pivotAttributes = []): iterable
    {
        return Model::withoutEvents(function () use ($models, $pivotAttributes) {
            return $this->saveMany($models, $pivotAttributes);
        });
    }

    /**
     * Create a new instance of the related model.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function create(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
        $attributes = array_merge($this->getQuery()->pendingAttributes, $attributes);

        $instance = $this->related->newInstance($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

    /**
     * Create an array of new instances of the related models.
     *
     * @return array<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function createMany(iterable $records, array $joinings = []): array
    {
        $instances = [];

        foreach ($records as $key => $record) {
            $instances[] = $this->create($record, (array) ($joinings[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $instances;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfJoin($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param \Hypervel\Database\Eloquent\Builder<TDeclaringModel> $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfJoin(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->select($columns);

        $query->from($this->related->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $this->related->setTable($hash);

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
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
        if ($this->parent->exists) {
            $this->query->limit($value);
        } else {
            $column = $this->getExistenceCompareKey();

            $grammar = $this->query->getQuery()->getGrammar();

            if ($grammar instanceof MySqlGrammar && $grammar->useLegacyGroupLimit($this->query->getQuery())) {
                $column = 'pivot_' . last(explode('.', $column));
            }

            $this->query->groupLimit($value, $column);
        }

        return $this;
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignPivotKeyName();
    }

    /**
     * Specify that the pivot table has creation and update timestamps.
     *
     * @return $this
     */
    public function withTimestamps(string|false|null $createdAt = null, string|false|null $updatedAt = null): static
    {
        $this->pivotCreatedAt = $createdAt !== false ? $createdAt : null;
        $this->pivotUpdatedAt = $updatedAt !== false ? $updatedAt : null;

        $pivots = array_filter([
            $createdAt !== false ? $this->createdAt() : null,
            $updatedAt !== false ? $this->updatedAt() : null,
        ]);

        $this->withTimestamps = ! empty($pivots);

        return $this->withTimestamps ? $this->withPivot($pivots) : $this;
    }

    /**
     * Get the name of the "created at" column.
     */
    public function createdAt(): string
    {
        return $this->pivotCreatedAt ?? $this->parent->getCreatedAtColumn() ?? Model::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function updatedAt(): string
    {
        return $this->pivotUpdatedAt ?? $this->parent->getUpdatedAtColumn() ?? Model::UPDATED_AT;
    }

    /**
     * Get the foreign key for the relation.
     */
    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Get the fully qualified foreign key for the relation.
     */
    public function getQualifiedForeignPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->foreignPivotKey);
    }

    /**
     * Get the "related key" for the relation.
     */
    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the fully qualified "related key" for the relation.
     */
    public function getQualifiedRelatedPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->relatedPivotKey);
    }

    /**
     * Get the parent key for the relationship.
     */
    public function getParentKeyName(): string
    {
        return $this->parentKey;
    }

    /**
     * Get the fully qualified parent key name for the relation.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->parentKey);
    }

    /**
     * Get the related key for the relationship.
     */
    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
    }

    /**
     * Get the fully qualified related key name for the relation.
     */
    public function getQualifiedRelatedKeyName(): string
    {
        return $this->related->qualifyColumn($this->relatedKey);
    }

    /**
     * Get the intermediate table for the relationship.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the relationship name for the relationship.
     */
    public function getRelationName(): ?string
    {
        return $this->relationName;
    }

    /**
     * Get the name of the pivot accessor for this relationship.
     *
     * @return TAccessor
     */
    public function getPivotAccessor(): string
    {
        return $this->accessor;
    }

    /**
     * Get the pivot columns for this relationship.
     */
    public function getPivotColumns(): array
    {
        return $this->pivotColumns;
    }

    /**
     * Qualify the given column name by the pivot table.
     *
     * @param \Hypervel\Contracts\Database\Query\Expression|string $column
     * @return \Hypervel\Contracts\Database\Query\Expression|string
     */
    public function qualifyPivotColumn(mixed $column): mixed
    {
        if ($this->query->getQuery()->getGrammar()->isExpression($column)) {
            return $column;
        }

        return str_contains($column, '.')
            ? $column
            : $this->table . '.' . $column;
    }
}
