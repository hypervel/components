<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use BadMethodCallException;
use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\RelationNotFoundException;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Support\Str;
use InvalidArgumentException;

use function Hypervel\Support\enum_value;

/** @mixin \Hypervel\Database\Eloquent\Builder */
trait QueriesRelationships
{
    /**
     * Add a relationship count / exists condition to the query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @return $this
     *
     * @throws \RuntimeException
     */
    public function has(Relation|string $relation, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            if (str_contains($relation, '.')) {
                // @phpstan-ignore argument.type (callback template types don't narrow through forwarding)
                return $this->hasNested($relation, $operator, $count, $boolean, $callback);
            }

            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if ($relation instanceof MorphTo) {
            // @phpstan-ignore argument.type (callback template types don't narrow through forwarding)
            return $this->hasMorph($relation, ['*'], $operator, $count, $boolean, $callback);
        }

        // If we only need to check for the existence of the relation, then we can optimize
        // the subquery to only run a "where exists" clause instead of this full "count"
        // clause. This will make these queries run much faster compared with a count.
        $method = $this->canUseExistsForExistenceCheck($operator, $count)
            ? 'getRelationExistenceQuery'
            : 'getRelationExistenceCountQuery';

        $hasQuery = $relation->{$method}(
            $relation->getRelated()->newQueryWithoutRelationships(), $this
        );

        // Next we will call any given callback as an "anonymous" scope so they can get the
        // proper logical grouping of the where clauses if needed by this Eloquent query
        // builder. Then, we will be ready to finalize and return this query instance.
        if ($callback) {
            $hasQuery->callScope($callback);
        }

        return $this->addHasWhere(
            $hasQuery, $relation, $operator, $count, $boolean
        );
    }

    /**
     * Add nested relationship count / exists conditions to the query.
     *
     * Sets up recursive call to whereHas until we finish the nested relation.
     *
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<*>): mixed)|null  $callback
     * @return $this
     */
    protected function hasNested(string $relations, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        $relations = explode('.', $relations);

        $initialRelations = [...$relations];

        $doesntHave = $operator === '<' && $count === 1;

        if ($doesntHave) {
            $operator = '>=';
            $count = 1;
        }

        $closure = function ($q) use (&$closure, &$relations, $operator, $count, $callback, $initialRelations) {
            // If the same closure is called multiple times, reset the relation array to loop through them again...
            if ($count === 1 && empty($relations)) {
                $relations = [...$initialRelations];

                array_shift($relations);
            }

            // In order to nest "has", we need to add count relation constraints on the
            // callback Closure. We'll do this by simply passing the Closure its own
            // reference to itself so it calls itself recursively on each segment.
            count($relations) > 1
                ? $q->whereHas(array_shift($relations), $closure)
                : $q->has(array_shift($relations), $operator, $count, 'and', $callback);
        };

        return $this->has(array_shift($relations), $doesntHave ? '<' : '>=', 1, $boolean, $closure);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or".
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|string  $relation
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function orHas(Relation|string $relation, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @return $this
     */
    public function doesntHave(Relation|string $relation, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or".
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|string  $relation
     * @return $this
     */
    public function orDoesntHave(Relation|string $relation): static
    {
        return $this->doesntHave($relation, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function whereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * Also load the relationship with the same condition.
     *
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<*>|\Hypervel\Database\Eloquent\Relations\Relation<*, *, *>): mixed)|null  $callback
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function withWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->whereHas(Str::before($relation, ':'), $callback, $operator, $count)
            ->with($callback ? [$relation => fn ($query) => $callback($query)] : $relation);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function orWhereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @return $this
     */
    public function whereDoesntHave(Relation|string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|null  $callback
     * @return $this
     */
    public function orWhereDoesntHave(Relation|string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @return $this
     */
    public function hasMorph(MorphTo|string $relation, string|array $types, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        $types = (array) $types;

        $checkMorphNull = $types === ['*']
            && (($operator === '<' && $count >= 1)
                || ($operator === '<=' && $count >= 0)
                || ($operator === '=' && $count === 0)
                || ($operator === '!=' && $count >= 1));

        if ($types === ['*']) {
            // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
            $types = $this->model->newModelQuery()->distinct()->pluck($relation->getMorphType())
                ->filter()
                ->map(fn ($item) => enum_value($item))
                ->all();
        }

        if (empty($types)) {
            return $this->where(new Expression('0'), $operator, $count, $boolean);
        }

        foreach ($types as &$type) {
            $type = Relation::getMorphedModel($type) ?? $type;
        }

        return $this->where(function ($query) use ($relation, $callback, $operator, $count, $types, $checkMorphNull) {
            foreach ($types as $type) {
                $query->orWhere(function ($query) use ($relation, $callback, $operator, $count, $type) {
                    $belongsTo = $this->getBelongsToRelation($relation, $type);

                    if ($callback) {
                        $callback = function ($query) use ($callback, $type) {
                            return $callback($query, $type);
                        };
                    }

                    // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
                    $query->where($this->qualifyColumn($relation->getMorphType()), '=', (new $type)->getMorphClass())
                        ->whereHas($belongsTo, $callback, $operator, $count);
                });
            }

            $query->when($checkMorphNull, fn (self $query) => $query->orWhereMorphedTo($relation, null));
        }, null, null, $boolean);
    }

    /**
     * Get the BelongsTo relationship for a single polymorphic type.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, TDeclaringModel>  $relation
     * @param  class-string<TRelatedModel>  $type
     * @return \Hypervel\Database\Eloquent\Relations\BelongsTo<TRelatedModel, TDeclaringModel>
     */
    protected function getBelongsToRelation(MorphTo $relation, string $type): BelongsTo
    {
        $belongsTo = Relation::noConstraints(function () use ($relation, $type) {
            return $this->model->belongsTo(
                $type,
                $relation->getForeignKeyName(),
                $relation->getOwnerKeyName()
            );
        });

        $belongsTo->getQuery()->mergeConstraintsFrom($relation->getQuery());

        return $belongsTo;
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function orHasMorph(MorphTo|string $relation, string|array $types, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @return $this
     */
    public function doesntHaveMorph(MorphTo|string $relation, string|array $types, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->hasMorph($relation, $types, '<', 1, $boolean, $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @return $this
     */
    public function orDoesntHaveMorph(MorphTo|string $relation, string|array $types): static
    {
        return $this->doesntHaveMorph($relation, $types, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function whereHasMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    public function orWhereHasMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @return $this
     */
    public function whereDoesntHaveMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null): static
    {
        return $this->doesntHaveMorph($relation, $types, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed)|null  $callback
     * @return $this
     */
    public function orWhereDoesntHaveMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null): static
    {
        return $this->doesntHaveMorph($relation, $types, 'or', $callback);
    }

    /**
     * Add a basic where clause to a relationship query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function whereRelation(Relation|string $relation, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHas($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a basic where clause to a relationship query and eager-load the relationship with the same conditions.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|string  $relation
     * @param  \Closure|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function withWhereRelation(Relation|string $relation, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereRelation($relation, $column, $operator, $value)
            ->with([
                $relation => fn ($query) => $column instanceof Closure
                    ? $column($query)
                    : $query->where($column, $operator, $value),
            ]);
    }

    /**
     * Add an "or where" clause to a relationship query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function orWhereRelation(Relation|string $relation, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHas($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a basic count / exists condition to a relationship query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function whereDoesntHaveRelation(Relation|string $relation, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHave($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add an "or where" clause to a relationship query.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<TRelatedModel, *, *>|string  $relation
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function orWhereDoesntHaveRelation(Relation|string $relation, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHave($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with a where clause.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function whereMorphRelation(MorphTo|string $relation, string|array $types, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHasMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with an "or where" clause.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function orWhereMorphRelation(MorphTo|string $relation, string|array $types, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHasMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with a doesn't have clause.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function whereMorphDoesntHaveRelation(MorphTo|string $relation, string|array $types, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHaveMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with an "or doesn't have" clause.
     *
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (\Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|string|array|\Hypervel\Database\Query\Expression  $column
     * @return $this
     */
    public function orWhereMorphDoesntHaveRelation(MorphTo|string $relation, string|array $types, Closure|string|array|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHaveMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a morph-to relationship condition to the query.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  \Hypervel\Database\Eloquent\Model|iterable<int, \Hypervel\Database\Eloquent\Model>|string|null  $model
     * @return $this
     */
    public function whereMorphedTo(MorphTo|string $relation, mixed $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (is_null($model)) {
            // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
            return $this->whereNull($relation->qualifyColumn($relation->getMorphType()), $boolean);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap)) {
                $model = array_search($model, $morphMap, true);
            }

            // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
            return $this->where($relation->qualifyColumn($relation->getMorphType()), $model, null, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereMorphedTo method may not be empty.');
        }

        return $this->where(function ($query) use ($relation, $models) {
            $models->groupBy(fn ($model) => $model->getMorphClass())->each(function ($models) use ($query, $relation) {
                $query->orWhere(function ($query) use ($relation, $models) {
                    // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
                    $query->where($relation->qualifyColumn($relation->getMorphType()), $models->first()->getMorphClass())
                        // @phpstan-ignore method.notFound (getForeignKeyName exists on MorphTo, not base Relation)
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Add a not morph-to relationship condition to the query.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  \Hypervel\Database\Eloquent\Model|iterable<int, \Hypervel\Database\Eloquent\Model>|string  $model
     * @return $this
     */
    public function whereNotMorphedTo(MorphTo|string $relation, mixed $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap)) {
                $model = array_search($model, $morphMap, true);
            }

            // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
            return $this->whereNot($relation->qualifyColumn($relation->getMorphType()), '<=>', $model, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereNotMorphedTo method may not be empty.');
        }

        return $this->whereNot(function ($query) use ($relation, $models) {
            $models->groupBy(fn ($model) => $model->getMorphClass())->each(function ($models) use ($query, $relation) {
                $query->orWhere(function ($query) use ($relation, $models) {
                    // @phpstan-ignore method.notFound (getMorphType exists on MorphTo, not base Relation)
                    $query->where($relation->qualifyColumn($relation->getMorphType()), '<=>', $models->first()->getMorphClass())
                        // @phpstan-ignore method.notFound (getForeignKeyName exists on MorphTo, not base Relation)
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Add a morph-to relationship condition to the query with an "or where" clause.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  \Hypervel\Database\Eloquent\Model|iterable<int, \Hypervel\Database\Eloquent\Model>|string|null  $model
     * @return $this
     */
    public function orWhereMorphedTo(MorphTo|string $relation, mixed $model): static
    {
        return $this->whereMorphedTo($relation, $model, 'or');
    }

    /**
     * Add a not morph-to relationship condition to the query with an "or where" clause.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|string  $relation
     * @param  \Hypervel\Database\Eloquent\Model|iterable<int, \Hypervel\Database\Eloquent\Model>|string  $model
     * @return $this
     */
    public function orWhereNotMorphedTo(MorphTo|string $relation, mixed $model): static
    {
        return $this->whereNotMorphedTo($relation, $model, 'or');
    }

    /**
     * Add a "belongs to" relationship where clause to the query.
     *
     * @param  \Hypervel\Database\Eloquent\Model|\Hypervel\Database\Eloquent\Collection<int, \Hypervel\Database\Eloquent\Model>  $related
     * @return $this
     *
     * @throws \Hypervel\Database\Eloquent\RelationNotFoundException
     */
    public function whereBelongsTo(mixed $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        if (! $related instanceof EloquentCollection) {
            $relatedCollection = $related->newCollection([$related]);
        } else {
            $relatedCollection = $related;

            $related = $relatedCollection->first();
        }

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereBelongsTo method may not be empty.');
        }

        if ($relationshipName === null) {
            $relationshipName = Str::camel(class_basename($related));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsTo) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsTo::class);
        }

        $this->whereIn(
            $relationship->getQualifiedForeignKeyName(),
            $relatedCollection->pluck($relationship->getOwnerKeyName())->toArray(),
            $boolean,
        );

        return $this;
    }

    /**
     * Add a "BelongsTo" relationship with an "or where" clause to the query.
     *
     * @return $this
     *
     * @throws \RuntimeException
     */
    public function orWhereBelongsTo(mixed $related, ?string $relationshipName = null): static
    {
        return $this->whereBelongsTo($related, $relationshipName, 'or');
    }

    /**
     * Add a "belongs to many" relationship where clause to the query.
     *
     * @param  \Hypervel\Database\Eloquent\Model|\Hypervel\Database\Eloquent\Collection<int, \Hypervel\Database\Eloquent\Model>  $related
     * @return $this
     *
     * @throws \Hypervel\Database\Eloquent\RelationNotFoundException
     */
    public function whereAttachedTo(mixed $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        $relatedCollection = $related instanceof EloquentCollection ? $related : $related->newCollection([$related]);

        $related = $relatedCollection->first();

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereAttachedTo method may not be empty.');
        }

        if ($relationshipName === null) {
            $relationshipName = Str::plural(Str::camel(class_basename($related)));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsToMany) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsToMany::class);
        }

        $this->has(
            $relationshipName,
            boolean: $boolean,
            callback: fn (Builder $query) => $query->whereKey($relatedCollection->pluck($related->getKeyName())),
        );

        return $this;
    }

    /**
     * Add a "belongs to many" relationship with an "or where" clause to the query.
     *
     * @return $this
     *
     * @throws \RuntimeException
     */
    public function orWhereAttachedTo(mixed $related, ?string $relationshipName = null): static
    {
        return $this->whereAttachedTo($related, $relationshipName, 'or');
    }

    /**
     * Add subselect queries to include an aggregate value for a relationship.
     *
     * @param  \Hypervel\Database\Query\Expression|string  $column
     * @return $this
     */
    public function withAggregate(mixed $relations, Expression|string $column, ?string $function = null): static
    {
        if (empty($relations)) {
            return $this;
        }

        if (is_null($this->query->columns)) {
            $this->query->select([$this->query->from.'.*']);
        }

        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($this->parseWithRelations($relations) as $name => $constraints) {
            // First we will determine if the name has been aliased using an "as" clause on the name
            // and if it has we will extract the actual relationship name and the desired name of
            // the resulting column. This allows multiple aggregates on the same relationships.
            $segments = explode(' ', $name);

            unset($alias);

            if (count($segments) === 3 && Str::lower($segments[1]) === 'as') {
                [$name, $alias] = [$segments[0], $segments[2]];
            }

            $relation = $this->getRelationWithoutConstraints($name);

            if ($function) {
                if ($this->getQuery()->getGrammar()->isExpression($column)) {
                    $aggregateColumn = $this->getQuery()->getGrammar()->getValue($column);
                } else {
                    $hashedColumn = $this->getRelationHashedColumn($column, $relation);

                    $aggregateColumn = $this->getQuery()->getGrammar()->wrap(
                        $column === '*' ? $column : $relation->getRelated()->qualifyColumn($hashedColumn)
                    );
                }

                $expression = $function === 'exists' ? $aggregateColumn : sprintf('%s(%s)', $function, $aggregateColumn);
            } else {
                $expression = $this->getQuery()->getGrammar()->getValue($column);
            }

            // Here, we will grab the relationship sub-query and prepare to add it to the main query
            // as a sub-select. First, we'll get the "has" query and use that to get the relation
            // sub-query. We'll format this relationship name and append this column if needed.
            // @phpstan-ignore-next-line (return type from mixin chain loses Eloquent\Builder context)
            $query = $relation->getRelationExistenceQuery(
                $relation->getRelated()->newQuery(), $this, new Expression($expression)
            )->setBindings([], 'select');

            // @phpstan-ignore method.notFound ($query is Eloquent\Builder, not Query\Builder)
            $query->callScope($constraints);

            // @phpstan-ignore method.notFound ($query is Eloquent\Builder, not Query\Builder)
            $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

            // If the query contains certain elements like orderings / more than one column selected
            // then we will remove those elements from the query so that it will execute properly
            // when given to the database. Otherwise, we may receive SQL errors or poor syntax.
            $query->orders = null;
            $query->setBindings([], 'order');

            if (count($query->columns) > 1) {
                $query->columns = [$query->columns[0]];
                $query->bindings['select'] = [];
            }

            // Finally, we will make the proper column alias to the query and run this sub-select on
            // the query builder. Then, we will return the builder instance back to the developer
            // for further constraint chaining that needs to take place on the query as needed.
            $alias ??= Str::snake(
                preg_replace(
                    '/[^[:alnum:][:space:]_]/u',
                    '',
                    sprintf('%s %s %s', $name, $function, strtolower($this->getQuery()->getGrammar()->getValue($column)))
                )
            );

            if ($function === 'exists') {
                // @phpstan-ignore method.notFound (selectRaw returns $this, not Query\Builder)
                $this->selectRaw(
                    sprintf('exists(%s) as %s', $query->toSql(), $this->getQuery()->grammar->wrap($alias)),
                    $query->getBindings()
                )->withCasts([$alias => 'bool']);
            } else {
                $this->selectSub(
                    $function ? $query : $query->limit(1),
                    $alias
                );
            }
        }

        return $this;
    }

    /**
     * Get the relation hashed column name for the given column and relation.
     *
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>  $relation
     */
    protected function getRelationHashedColumn(string $column, Relation $relation): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getQuery()->from === $relation->getQuery()->getQuery()->from
            ? "{$relation->getRelationCountHash(false)}.$column"
            : $column;
    }

    /**
     * Add subselect queries to count the relations.
     *
     * @return $this
     */
    public function withCount(mixed $relations): static
    {
        return $this->withAggregate(is_array($relations) ? $relations : func_get_args(), '*', 'count');
    }

    /**
     * Add subselect queries to include the max of the relation's column.
     *
     * @param  \Hypervel\Database\Query\Expression|string  $column
     * @return $this
     */
    public function withMax(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'max');
    }

    /**
     * Add subselect queries to include the min of the relation's column.
     *
     * @param  \Hypervel\Database\Query\Expression|string  $column
     * @return $this
     */
    public function withMin(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'min');
    }

    /**
     * Add subselect queries to include the sum of the relation's column.
     *
     * @param  \Hypervel\Database\Query\Expression|string  $column
     * @return $this
     */
    public function withSum(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'sum');
    }

    /**
     * Add subselect queries to include the average of the relation's column.
     *
     * @param  \Hypervel\Database\Query\Expression|string  $column
     * @return $this
     */
    public function withAvg(string|array $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'avg');
    }

    /**
     * Add subselect queries to include the existence of related models.
     *
     * @return $this
     */
    public function withExists(string|array $relation): static
    {
        return $this->withAggregate($relation, '*', 'exists');
    }

    /**
     * Add the "has" condition where clause to the query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<*>  $hasQuery
     * @param  \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>  $relation
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    protected function addHasWhere(Builder $hasQuery, Relation $relation, string $operator, Expression|int $count, string $boolean): static
    {
        $hasQuery->mergeConstraintsFrom($relation->getQuery());

        return $this->canUseExistsForExistenceCheck($operator, $count)
            ? $this->addWhereExistsQuery($hasQuery->toBase(), $boolean, $operator === '<' && $count === 1)
            : $this->addWhereCountQuery($hasQuery->toBase(), $operator, $count, $boolean);
    }

    /**
     * Merge the where constraints from another query to the current query.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<*>  $from
     * @return $this
     */
    public function mergeConstraintsFrom(Builder $from): static
    {
        // @phpstan-ignore nullCoalesce.offset (defensive fallback)
        $whereBindings = $from->getQuery()->getRawBindings()['where'] ?? [];

        $wheres = $from->getQuery()->from !== $this->getQuery()->from
            ? $this->requalifyWhereTables(
                $from->getQuery()->wheres,
                $from->getQuery()->grammar->getValue($from->getQuery()->from),
                $this->getModel()->getTable()
            ) : $from->getQuery()->wheres;

        // Here we have some other query that we want to merge the where constraints from. We will
        // copy over any where constraints on the query as well as remove any global scopes the
        // query might have removed. Then we will return ourselves with the finished merging.
        return $this->withoutGlobalScopes(
            $from->removedScopes()
        )->mergeWheres(
            $wheres, $whereBindings
        );
    }

    /**
     * Updates the table name for any columns with a new qualified name.
     *
     * @param  array  $wheres
     * @param  string  $from
     * @param  string  $to
     * @return array
     */
    protected function requalifyWhereTables(array $wheres, string $from, string $to): array
    {
        return (new BaseCollection($wheres))->map(function ($where) use ($from, $to) {
            return (new BaseCollection($where))->map(function ($value) use ($from, $to) {
                return is_string($value) && str_starts_with($value, $from.'.')
                    ? $to.'.'.Str::afterLast($value, '.')
                    : $value;
            });
        })->toArray();
    }

    /**
     * Add a sub-query count clause to this query.
     *
     * @param  \Hypervel\Database\Query\Expression|int  $count
     * @return $this
     */
    protected function addWhereCountQuery(QueryBuilder $query, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and'): static
    {
        $this->query->addBinding($query->getBindings(), 'where');

        return $this->where(
            new Expression('('.$query->toSql().')'),
            $operator,
            is_numeric($count) ? new Expression($count) : $count,
            $boolean
        );
    }

    /**
     * Get the "has relation" base query instance.
     *
     * @return \Hypervel\Database\Eloquent\Relations\Relation<*, *, *>
     */
    protected function getRelationWithoutConstraints(string $relation): Relation
    {
        return Relation::noConstraints(function () use ($relation) {
            return $this->getModel()->{$relation}();
        });
    }

    /**
     * Check if we can run an "exists" query to optimize performance.
     *
     * @param  \Hypervel\Database\Query\Expression|int  $count
     */
    protected function canUseExistsForExistenceCheck(string $operator, Expression|int $count): bool
    {
        return ($operator === '>=' || $operator === '<') && $count === 1;
    }
}
