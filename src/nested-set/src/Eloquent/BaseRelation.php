<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder;
use Hypervel\NestedSet\NestedSet;
use InvalidArgumentException;

abstract class BaseRelation extends Relation
{
    /**
     * The nested-set query builder instance.
     *
     * @var QueryBuilder
     */
    protected EloquentBuilder $query;

    /**
     * Create a new nested set relation.
     */
    public function __construct(QueryBuilder $builder, Model $model)
    {
        if (! NestedSet::isNode($model)) {
            throw new InvalidArgumentException('Model must be node.');
        }

        parent::__construct($builder, $model);
    }

    /**
     * Determine whether a related node matches a parent.
     */
    abstract protected function matches(Model $model, Model $related): bool;

    /**
     * Add an eager constraint for a parent.
     */
    abstract protected function addEagerConstraint(QueryBuilder $query, Model $model): void;

    /**
     * Get the relation existence condition.
     */
    abstract protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string;

    /**
     * Get the relation existence query.
     */
    public function getRelationExistenceQuery(EloquentBuilder $query, EloquentBuilder $parentQuery, mixed $columns = ['*']): EloquentBuilder
    {
        // The relation owns an isolated aliased model; Eloquent applies the
        // caller's constraints to the returned builder afterward.
        $query = $this->getParent()->replicate()->newQuery();
        $query->select($columns);

        $table = $query->getModel()->getTable();

        $query->from($table . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $grammar = $query->getQuery()->getGrammar();

        $condition = $this->relationExistenceCondition(
            $grammar->wrapTable($hash),
            $grammar->wrapTable($parentQuery->getModel()->getTable()),
            $grammar->wrap($this->parent->getLftName()), /* @phpstan-ignore method.notFound */
            $grammar->wrap($this->parent->getRgtName()) /* @phpstan-ignore method.notFound */
        );

        $query->whereRaw($condition);

        foreach (array_keys($this->scopeValues($this->parent)) as $attribute) {
            $relatedColumn = $hash . '.' . $attribute;
            $parentColumn = $parentQuery->getModel()->getTable() . '.' . $attribute;

            $query->where(function (EloquentBuilder $query) use ($relatedColumn, $parentColumn) {
                $query->whereColumn($relatedColumn, '=', $parentColumn)
                    ->orWhere(function (EloquentBuilder $query) use ($relatedColumn, $parentColumn) {
                        $query->whereNull($relatedColumn)
                            ->whereNull($parentColumn);
                    });
            });
        }

        return $query;
    }

    /**
     * Initialize the relation on a set of models.
     */
    public function initRelation(array $models, string $relation): array
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
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $models = $this->prepareEagerModels($models);

        if ($models === []) {
            $this->query->whereRaw('0 = 1');

            return;
        }

        $this->query->whereNested(function (Builder $inner) use ($models) {
            // We will use this query in order to apply constraints to the
            // base query builder
            /** @var QueryBuilder $outer */
            $outer = $this->parent->newQuery()->setQuery($inner);

            $this->constrainEagerModels($outer, $models);
        });
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $indexed = $this->shouldIndexResults($models)
            ? $this->indexResults($results)
            : null;

        foreach ($models as $model) {
            $related = $indexed === null
                ? $this->matchForModel($model, $results)
                : $this->matchFromIndex($model, $indexed);

            $model->setRelation($relation, $related);
        }

        return $models;
    }

    /**
     * Match query results for one parent.
     */
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
     * Apply eager constraints for the prepared parent models.
     */
    protected function constrainEagerModels(QueryBuilder $query, array $models): void
    {
        foreach ($models as $model) {
            $this->addEagerConstraint($query, $model);
        }
    }

    /**
     * Deduplicate the parent models used to constrain an eager query.
     */
    protected function prepareEagerModels(array $models): array
    {
        $result = [];
        $seenKeys = [];
        $seenObjects = [];

        foreach ($models as $model) {
            $scope = $this->scopeKey($model);
            $key = $model->getKey();

            if ($key === null) {
                $objectId = spl_object_id($model);

                if (isset($seenObjects[$scope][$objectId])) {
                    continue;
                }

                $seenObjects[$scope][$objectId] = true;
            } else {
                if (isset($seenKeys[$scope][$key])) {
                    continue;
                }

                $seenKeys[$scope][$key] = true;
            }

            $result[] = $model;
        }

        return $result;
    }

    /**
     * Determine whether eager results should be indexed by tree scope.
     */
    protected function shouldIndexResults(array $models): bool
    {
        return count($models) > 1;
    }

    /**
     * Index eager results by exact nested-set scope while preserving query order.
     */
    protected function indexResults(Collection $results): array
    {
        /** @var array<string, array{models: list<Model>}> $indexed */
        $indexed = [];

        foreach ($results as $related) {
            $scope = $this->scopeKey($related);

            if (! isset($indexed[$scope])) {
                $indexed[$scope] = [
                    'models' => [],
                ];
            }

            $indexed[$scope]['models'][] = $related;
        }

        return $indexed;
    }

    /**
     * Match a parent from its exact scope bucket.
     */
    protected function matchFromIndex(Model $model, array $indexed): Collection
    {
        $bucket = $indexed[$this->scopeKey($model)]['models'] ?? [];

        return $this->matchForModel($model, $this->related->newCollection($bucket));
    }

    /**
     * Get the normalized nested-set scope values for a node.
     *
     * @return array<string, null|int|string>
     */
    protected function scopeValues(Model $model): array
    {
        return $model->getNestedSetScope(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the stable identity key for a node's nested-set scope.
     */
    protected function scopeKey(Model $model): string
    {
        return $model->getNestedSetScopeKey(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the plain foreign key.
     */
    public function getForeignKeyName(): string
    {
        return $this->parent->getParentIdName(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the qualified foreign key name.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->qualifyColumn($this->getForeignKeyName());
    }
}
