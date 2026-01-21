<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations;

use Hypervel\Database\Contracts\Eloquent\SupportsPartialRelations;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Concerns\CanBeOneOfMany;
use Hypervel\Database\Eloquent\Relations\Concerns\ComparesRelatedModels;
use Hypervel\Database\Eloquent\Relations\Concerns\SupportsDefaultModels;
use Hypervel\Database\Query\JoinClause;

/**
 * @template TRelatedModel of \Hypervel\Database\Eloquent\Model
 * @template TDeclaringModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Relations\MorphOneOrMany<TRelatedModel, TDeclaringModel, ?TRelatedModel>
 */
class MorphOne extends MorphOneOrMany implements SupportsPartialRelations
{
    use CanBeOneOfMany, ComparesRelatedModels, SupportsDefaultModels;

    /** @inheritDoc */
    public function getResults()
    {
        if (is_null($this->getParentKey())) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /** @inheritDoc */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /** @inheritDoc */
    public function match(array $models, EloquentCollection $results, $relation)
    {
        return $this->matchOne($models, $results, $relation);
    }

    /** @inheritDoc */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add constraints for inner join subselect for one of many relationships.
     *
     * @param  \Hypervel\Database\Eloquent\Builder<TRelatedModel>  $query
     */
    public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, ?string $aggregate = null): void
    {
        $query->addSelect($this->foreignKey, $this->morphType);
    }

    /**
     * Get the columns that should be selected by the one of many subquery.
     */
    public function getOneOfManySubQuerySelectColumns(): array|string
    {
        return [$this->foreignKey, $this->morphType];
    }

    /**
     * Add join query constraints for one of many relationships.
     */
    public function addOneOfManyJoinSubQueryConstraints(JoinClause $join): void
    {
        $join
            ->on($this->qualifySubSelectColumn($this->morphType), '=', $this->qualifyRelatedColumn($this->morphType))
            ->on($this->qualifySubSelectColumn($this->foreignKey), '=', $this->qualifyRelatedColumn($this->foreignKey));
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param  TDeclaringModel  $parent
     * @return TRelatedModel
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return tap($this->related->newInstance(), function ($instance) use ($parent) {
            $instance->setAttribute($this->getForeignKeyName(), $parent->{$this->localKey})
                ->setAttribute($this->getMorphType(), $this->morphClass);

            $this->applyInverseRelationToModel($instance, $parent);
        });
    }

    /**
     * Get the value of the model's foreign key.
     *
     * @param  TRelatedModel  $model
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }
}
