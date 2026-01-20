<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Closure;
use Hyperf\Database\Model\Concerns\QueriesRelationships as BaseQueriesRelationships;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Relations\Contracts\Relation as RelationContract;

/**
 * @mixin \Hypervel\Database\Eloquent\Builder
 */
trait QueriesRelationships
{
    use BaseQueriesRelationships {
        has as private baseHas;
        orHas as private baseOrHas;
        doesntHave as private baseDoesntHave;
        orDoesntHave as private baseOrDoesntHave;
        whereHas as private baseWhereHas;
        orWhereHas as private baseOrWhereHas;
        whereDoesntHave as private baseWhereDoesntHave;
        orWhereDoesntHave as private baseOrWhereDoesntHave;
        hasMorph as private baseHasMorph;
        orHasMorph as private baseOrHasMorph;
        doesntHaveMorph as private baseDoesntHaveMorph;
        orDoesntHaveMorph as private baseOrDoesntHaveMorph;
        whereHasMorph as private baseWhereHasMorph;
        orWhereHasMorph as private baseOrWhereHasMorph;
        whereDoesntHaveMorph as private baseWhereDoesntHaveMorph;
        orWhereDoesntHaveMorph as private baseOrWhereDoesntHaveMorph;
        whereRelation as private baseWhereRelation;
        orWhereRelation as private baseOrWhereRelation;
        whereMorphRelation as private baseWhereMorphRelation;
        orWhereMorphRelation as private baseOrWhereMorphRelation;
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param string $operator
     * @param int $count
     * @param string $boolean
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @return $this
     */
    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        return $this->baseHas($relation, $operator, $count, $boolean, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orHas($relation, $operator = '>=', $count = 1)
    {
        return $this->baseOrHas($relation, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param string $boolean
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @return $this
     */
    public function doesntHave($relation, $boolean = 'and', ?Closure $callback = null)
    {
        return $this->baseDoesntHave($relation, $boolean, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @return $this
     */
    public function orDoesntHave($relation)
    {
        return $this->baseOrDoesntHave($relation);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->baseWhereHas($relation, $callback, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orWhereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->baseOrWhereHas($relation, $callback, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @return $this
     */
    public function whereDoesntHave($relation, ?Closure $callback = null)
    {
        return $this->baseWhereDoesntHave($relation, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed) $callback
     * @return $this
     */
    public function orWhereDoesntHave($relation, ?Closure $callback = null)
    {
        return $this->baseOrWhereDoesntHave($relation, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param string $operator
     * @param int $count
     * @param string $boolean
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @return $this
     */
    public function hasMorph($relation, $types, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        return $this->baseHasMorph($relation, $types, $operator, $count, $boolean, $callback);
    }

    /**
     * @param \Hyperf\Database\Model\Relations\MorphTo|\Hyperf\Database\Model\Relations\Relation|string $relation
     * @param array|string $types
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orHasMorph($relation, $types, $operator = '>=', $count = 1): Builder|static
    {
        return $this->baseOrHasMorph($relation, $types, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param string $boolean
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @return $this
     */
    public function doesntHaveMorph($relation, $types, $boolean = 'and', ?Closure $callback = null)
    {
        return $this->baseDoesntHaveMorph($relation, $types, $boolean, $callback);
    }

    /**
     * @param \Hyperf\Database\Model\Relations\MorphTo|\Hyperf\Database\Model\Relations\Relation|string $relation
     * @param array|string $types
     * @return $this
     */
    public function orDoesntHaveMorph($relation, $types): Builder|static
    {
        return $this->baseOrDoesntHaveMorph($relation, $types);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function whereHasMorph($relation, $types, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->baseWhereHasMorph($relation, $types, $callback, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @param string $operator
     * @param int $count
     * @return $this
     */
    public function orWhereHasMorph($relation, $types, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        return $this->baseOrWhereHasMorph($relation, $types, $callback, $operator, $count);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @return $this
     */
    public function whereDoesntHaveMorph($relation, $types, ?Closure $callback = null)
    {
        return $this->baseWhereDoesntHaveMorph($relation, $types, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param null|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>, string): mixed) $callback
     * @return $this
     */
    public function orWhereDoesntHaveMorph($relation, $types, ?Closure $callback = null)
    {
        return $this->baseOrWhereDoesntHaveMorph($relation, $types, $callback);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|\Hyperf\Database\Query\Expression|string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function whereRelation($relation, $column, $operator = null, $value = null): Builder|static
    {
        return $this->baseWhereRelation($relation, $column, $operator, $value);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|\Hyperf\Database\Query\Expression|string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhereRelation($relation, $column, $operator = null, $value = null): Builder|static
    {
        return $this->baseOrWhereRelation($relation, $column, $operator, $value);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param array|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|\Hyperf\Database\Query\Expression|string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function whereMorphRelation($relation, $types, $column, $operator = null, $value = null): Builder|static
    {
        return $this->baseWhereMorphRelation($relation, $types, $column, $operator, $value);
    }

    /**
     * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
     *
     * @param  \Hypervel\Database\Eloquent\Relations\MorphTo<*, *>|RelationContract<TRelatedModel, *, *>|string  $relation
     * @param array|string $types
     * @param array|(Closure(\Hypervel\Database\Eloquent\Builder<TRelatedModel>): mixed)|\Hyperf\Database\Query\Expression|string $column
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function orWhereMorphRelation($relation, $types, $column, $operator = null, $value = null): Builder|static
    {
        return $this->baseOrWhereMorphRelation($relation, $types, $column, $operator, $value);
    }
}
