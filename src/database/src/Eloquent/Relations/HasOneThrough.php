<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Contracts\Database\Eloquent\SupportsPartialRelations;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\CanBeOneOfMany;
use Hypervel\Database\Eloquent\Relations\Concerns\ComparesRelatedModels;
use Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Hypervel\Database\Eloquent\Relations\Concerns\SupportsDefaultModels;
use Hypervel\Database\Query\JoinClause;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TIntermediateModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel, ?TRelatedModel>
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
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, ?string $aggregate = null): void
    {
        $query->addSelect([$this->getQualifiedFirstKeyName()]);

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

    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }

    public function getParentKey(): mixed
    {
        return $this->farParent->getAttribute($this->localKey);
    }
}
