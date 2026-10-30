<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Contracts\Database\Eloquent\SupportsPartialRelations;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\CanBeOneOfMany;
use Hypervel\Database\Eloquent\Relations\Concerns\ComparesRelatedModels;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Hypervel\Database\Eloquent\Relations\Concerns\SupportsDefaultModels;
use Hypervel\Database\Query\JoinClause;
use Hypervel\Support\Str;

/**
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel, ?TRelatedModel>
 */
class HasOneThrough extends HasOneOrManyThrough implements SupportsPartialRelations
{
    use ComparesRelatedModels;
    use CanBeOneOfMany;
    use InteractsWithDictionary;
    use SupportsDefaultModels;

    public function getResults()
    {
        if (is_null($this->getParentKey())) {
            return $this->getDefaultFor($this->farParent);
        }

        return $this->first() ?: $this->getDefaultFor($this->farParent);
    }

    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    public function match(array $models, EloquentCollection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->localKey));

            if ($key !== null && isset($dictionary[$key])) {
                $value = $dictionary[$key];

                $model->setRelation(
                    $relation,
                    reset($value)
                );
            }
        }

        return $models;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query = parent::getRelationExistenceQuery($query, $parentQuery, $columns);

        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);

            if ($parentQuery->getQuery()->from !== $query->getQuery()->from
                && $parentQuery->getQuery()->from === $this->throughParent->getTable()) {
                $this->qualifyOneOfManyJoinWithThroughParentAlias($query);
            }
        }

        return $query;
    }

    /**
     * Point the one-of-many join at the aliased intermediate table of a self-referencing existence query.
     *
     * @param Builder<TRelatedModel> $query
     */
    protected function qualifyOneOfManyJoinWithThroughParentAlias(Builder $query): void
    {
        $joins = $query->getQuery()->joins;

        $alias = Str::afterLast($joins[0]->table, ' as ');

        $join = last($joins);

        foreach ($join->wheres as $key => $where) {
            if (($where['second'] ?? null) === $this->getQualifiedFirstKeyName()) {
                $join->wheres[$key]['second'] = $alias . '.' . $this->firstKey;
            }
        }
    }

    public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, ?string $aggregate = null): void
    {
        $query->addSelect([$this->getQualifiedFirstKeyName()]);

        foreach ($this->morphConstraints as $morphColumn => $morphClass) {
            $query->where($morphColumn, $morphClass);
        }

        // We need to join subqueries that aren't the inner-most subquery which is joined in the CanBeOneOfMany::ofMany method...
        if ($this->getOneOfManySubQuery() !== null) {
            // @phpstan-ignore argument.type (Builder param typed without template in inherited interface)
            $this->performJoin($query);
        }
    }

    public function getOneOfManySubQuerySelectColumns(): array|string
    {
        return [$this->getQualifiedFirstKeyName()];
    }

    public function addOneOfManyJoinSubQueryConstraints(JoinClause $join): void
    {
        $join->on($this->qualifySubSelectColumn($this->firstKey), '=', $this->getQualifiedFirstKeyName());
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param TDeclaringModel $parent
     * @return TRelatedModel
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance();
    }

    /**
     * Determine if the model is the related instance of the relationship.
     */
    public function is(?Model $model): bool
    {
        return ! is_null($model)
            && ! is_null($this->getParentKey())
            && $this->related->getTable() === $model->getTable()
            && ConnectionName::withoutWriteType($this->related->getConnectionName()) === ConnectionName::withoutWriteType($model->getConnectionName())
            && (clone $this->query)->whereKey($model->getKey())->exists();
    }

    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }

    public function getParentKey(): mixed
    {
        return $this->farParent->getAttribute($this->localKey);
    }
}
