<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\Relation;
use Hyperf\Database\Query\Builder;
use Hypervel\NestedSet\NestedSet;
use InvalidArgumentException;

abstract class BaseRelation extends Relation
{
    /**
     * The count of self joins.
     */
    protected static int $selfJoinCount = 0;

    /**
     * AncestorsRelation constructor.
     */
    public function __construct(QueryBuilder $builder, Model $model)
    {
        if (! NestedSet::isNode($model)) {
            throw new InvalidArgumentException('Model must be node.');
        }

        parent::__construct($builder, $model);
    }

    abstract protected function matches(Model $model, Model $related): bool;

    abstract protected function addEagerConstraint(QueryBuilder $query, Model $model): void;

    abstract protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string;

    /**
     * @param array $columns
     */
    public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parent, $columns = ['*']): mixed
    {
        /* @phpstan-ignore-next-line */
        $query = $this->getParent()->replicate()->newScopedQuery()->select($columns);

        $table = $query->getModel()->getTable();

        $query->from($table . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $grammar = $query->getQuery()->getGrammar();

        $condition = $this->relationExistenceCondition(
            $grammar->wrapTable($hash),
            $grammar->wrapTable($table),
            $grammar->wrap($this->parent->getLftName()), /* @phpstan-ignore-line */
            $grammar->wrap($this->parent->getRgtName()) /* @phpstan-ignore-line */
        );

        return $query->whereRaw($condition);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param string $relation
     */
    public function initRelation(array $models, $relation): array
    {
        return $models;
    }

    /**
     * Get a relationship join table hash.
     */
    public function getRelationCountHash(bool $incrementJoinCount = true): string
    {
        return 'nested_set_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return $this->query->get();
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereNested(function (Builder $inner) use ($models) {
            // We will use this query in order to apply constraints to the
            // base query builder
            $outer = $this->parent->newQuery()->setQuery($inner);

            foreach ($models as $model) {
                $this->addEagerConstraint($outer, $model);
            }
        });
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param string $relation
     */
    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $related = $this->matchForModel($model, $results);

            $model->setRelation($relation, $related);
        }

        return $models;
    }

    protected function matchForModel(Model $model, Collection $results): Collection
    {
        $result = $this->related->newCollection();

        foreach ($results as $related) {
            if ($this->matches($model, $related)) {
                $result->push($related);
            }
        }

        return $result;
    }

    /**
     * Get the plain foreign key.
     */
    public function getForeignKeyName(): mixed
    {
        // Return a stub value for relation
        // resolvers which need this function.
        return NestedSet::PARENT_ID;
    }
}
