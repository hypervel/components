<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Contracts\Database\Eloquent\Builder as BuilderContract;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Macroable;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 * @template TResult
 *
 * @mixin \Hypervel\Database\Eloquent\Builder<TRelatedModel>
 */
abstract class Relation implements BuilderContract
{
    use ForwardsCalls, Macroable {
        Macroable::__call as macroCall;
    }

    /**
     * The Eloquent query builder instance.
     *
     * @var \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    protected Builder $query;

    /**
     * The parent model instance.
     *
     * @var TDeclaringModel
     */
    protected Model $parent;

    /**
     * The related model instance.
     *
     * @var TRelatedModel
     */
    protected Model $related;

    /**
     * Indicates whether the eagerly loaded relation should implicitly return an empty collection.
     */
    protected bool $eagerKeysWereEmpty = false;

    /**
     * The context key for storing whether constraints are enabled.
     */
    protected const CONSTRAINTS_CONTEXT_KEY = '__database.relation.constraints';

    /**
     * An array to map morph names to their class names in the database.
     *
     * @var array<string, class-string<\Hypervel\Database\Eloquent\Model>>
     */
    public static array $morphMap = [];

    /**
     * Prevents morph relationships without a morph map.
     */
    protected static bool $requireMorphMap = false;

    /**
     * The count of self joins.
     */
    protected static int $selfJoinCount = 0;

    /**
     * Create a new relation instance.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param TDeclaringModel $parent
     */
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
     *
     * @template TReturn of mixed
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public static function noConstraints(Closure $callback): mixed
    {
        $previous = Context::get(static::CONSTRAINTS_CONTEXT_KEY, true);

        Context::set(static::CONSTRAINTS_CONTEXT_KEY, false);

        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return $callback();
        } finally {
            Context::set(static::CONSTRAINTS_CONTEXT_KEY, $previous);
        }
    }

    /**
     * Determine if constraints should be added to the relation query.
     */
    public static function shouldAddConstraints(): bool
    {
        return Context::get(static::CONSTRAINTS_CONTEXT_KEY, true);
    }

    /**
     * Set the base constraints on the relation query.
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array<int, TDeclaringModel> $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialize the relation on a set of models.
     *
     * @param array<int, TDeclaringModel> $models
     * @return array<int, TDeclaringModel>
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array<int, TDeclaringModel> $models
     * @param \Hypervel\Database\Eloquent\Collection<int, TRelatedModel> $results
     * @return array<int, TDeclaringModel>
     */
    abstract public function match(array $models, EloquentCollection $results, string $relation): array;

    /**
     * Get the results of the relationship.
     *
     * @return TResult
     */
    abstract public function getResults();

    /**
     * Get the relationship for eager loading.
     *
     * @return \Hypervel\Database\Eloquent\Collection<int, TRelatedModel>
     */
    public function getEager(): EloquentCollection
    {
        return $this->eagerKeysWereEmpty
            ? $this->related->newCollection()
            : $this->get();
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return TRelatedModel
     *
     * @throws \Hypervel\Database\Eloquent\ModelNotFoundException<TRelatedModel>
     * @throws \Hypervel\Database\MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*']): Model
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw (new ModelNotFoundException())->setModel(get_class($this->related));
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        // @phpstan-ignore return.type (Collection::first() generic type lost; count check above ensures non-null)
        return $result->first();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return \Hypervel\Support\Collection<int, TRelatedModel>
     */
    public function get(array $columns = ['*']): BaseCollection
    {
        return $this->query->get($columns);
    }

    /**
     * Touch all of the related models for the relationship.
     */
    public function touch(): void
    {
        $model = $this->getRelated();

        if (! $model::isIgnoringTouch()) {
            $this->rawUpdate([
                $model->getUpdatedAtColumn() => $model->freshTimestampString(),
            ]);
        }
    }

    /**
     * Run a raw update against the base query.
     */
    public function rawUpdate(array $attributes = []): int
    {
        return $this->query->withoutGlobalScopes()->update($attributes);
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param \Hypervel\Database\Eloquent\Builder<TDeclaringModel> $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery): Builder
    {
        return $this->getRelationExistenceQuery(
            $query,
            $parentQuery,
            new Expression('count(*)')
        )->setBindings([], 'select');
    }

    /**
     * Add the constraints for an internal relationship existence query.
     *
     * Essentially, these queries compare on column names like whereColumn.
     *
     * @param \Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     * @param \Hypervel\Database\Eloquent\Builder<TDeclaringModel> $parentQuery
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $this->getExistenceCompareKey() // @phpstan-ignore method.notFound (defined in subclasses)
        );
    }

    /**
     * Get a relationship join table hash.
     */
    public function getRelationCountHash(bool $incrementJoinCount = true): string
    {
        return 'laravel_reserved_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param array<int, TDeclaringModel> $models
     * @return array<int, null|int|string>
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        return (new BaseCollection($models))->map(function ($value) use ($key) {
            return $key ? $value->getAttribute($key) : $value->getKey();
        })->values()->unique(null, true)->sort()->all();
    }

    /**
     * Get the query builder that will contain the relationship constraints.
     *
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    protected function getRelationQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return \Hypervel\Database\Eloquent\Builder<TRelatedModel>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the base query builder driving the Eloquent builder.
     */
    public function getBaseQuery(): QueryBuilder
    {
        return $this->query->getQuery();
    }

    /**
     * Get a base query builder instance.
     */
    public function toBase(): QueryBuilder
    {
        return $this->query->toBase();
    }

    /**
     * Get the parent model of the relation.
     *
     * @return TDeclaringModel
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Get the fully qualified parent key name.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->getQualifiedKeyName();
    }

    /**
     * Get the related model of the relation.
     *
     * @return TRelatedModel
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Get the name of the "created at" column.
     */
    public function createdAt(): string
    {
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function updatedAt(): string
    {
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Get the name of the related model's "updated at" column.
     */
    public function relatedUpdatedAt(): string
    {
        return $this->related->getUpdatedAtColumn();
    }

    /**
     * Add a whereIn eager constraint for the given set of model keys to be loaded.
     *
     * @param null|\Hypervel\Database\Eloquent\Builder<TRelatedModel> $query
     */
    protected function whereInEager(string $whereIn, string $key, array $modelKeys, ?Builder $query = null): void
    {
        ($query ?? $this->query)->{$whereIn}($key, $modelKeys);

        if ($modelKeys === []) {
            $this->eagerKeysWereEmpty = true;
        }
    }

    /**
     * Get the name of the "where in" method for eager loading.
     */
    protected function whereInMethod(Model $model, string $key): string
    {
        return $model->getKeyName() === last(explode('.', $key))
            && in_array($model->getKeyType(), ['int', 'integer'])
                ? 'whereIntegerInRaw'
                : 'whereIn';
    }

    /**
     * Prevent polymorphic relationships from being used without model mappings.
     */
    public static function requireMorphMap(bool $requireMorphMap = true): void
    {
        static::$requireMorphMap = $requireMorphMap;
    }

    /**
     * Determine if polymorphic relationships require explicit model mapping.
     */
    public static function requiresMorphMap(): bool
    {
        return static::$requireMorphMap;
    }

    /**
     * Define the morph map for polymorphic relations and require all morphed models to be explicitly mapped.
     *
     * @param array<string, class-string<\Hypervel\Database\Eloquent\Model>> $map
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        static::requireMorphMap();

        return static::morphMap($map, $merge);
    }

    /**
     * Set or get the morph map for polymorphic relations.
     *
     * @param null|array<string, class-string<\Hypervel\Database\Eloquent\Model>> $map
     * @return array<string, class-string<\Hypervel\Database\Eloquent\Model>>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        $map = static::buildMorphMapFromModels($map);

        if (is_array($map)) {
            static::$morphMap = $merge && static::$morphMap
                ? $map + static::$morphMap
                : $map;
        }

        return static::$morphMap;
    }

    /**
     * Builds a table-keyed array from model class names.
     *
     * @param null|array<string, class-string<\Hypervel\Database\Eloquent\Model>>|list<class-string<\Hypervel\Database\Eloquent\Model>> $models
     * @return null|array<string, class-string<\Hypervel\Database\Eloquent\Model>>
     */
    protected static function buildMorphMapFromModels(?array $models = null): ?array
    {
        if (is_null($models) || ! array_is_list($models)) {
            // @phpstan-ignore return.type (returns the keyed array unchanged)
            return $models;
        }

        return array_combine(array_map(function ($model) {
            return (new $model())->getTable();
        }, $models), $models);
    }

    /**
     * Get the model associated with a custom polymorphic type.
     *
     * @return null|class-string<\Hypervel\Database\Eloquent\Model>
     */
    public static function getMorphedModel(string $alias): ?string
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Get the alias associated with a custom polymorphic class.
     *
     * @param class-string<\Hypervel\Database\Eloquent\Model> $className
     */
    public static function getMorphAlias(string $className): int|string
    {
        return array_search($className, static::$morphMap, strict: true) ?: $className;
    }

    /**
     * Handle dynamic method calls to the relationship.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->forwardDecoratedCallTo($this->query, $method, $parameters);
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;
    }
}
