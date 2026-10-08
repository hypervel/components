<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder;

/**
 * @template TModel of Model
 *
 * @extends BaseRelation<TModel>
 */
class SiblingsRelation extends BaseRelation
{
    /**
     * Whether to include the parent node itself.
     */
    protected bool $andSelf;

    /**
     * Create a new sibling relation.
     *
     * @param QueryBuilder<TModel> $builder
     * @param TModel $model
     */
    public function __construct(QueryBuilder $builder, Model $model, bool $andSelf = false)
    {
        $this->andSelf = $andSelf;

        parent::__construct($builder, $model);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! static::shouldAddConstraints()) {
            return;
        }

        if (! $this->prepareLazyParent()) {
            return;
        }

        $this->whereParentId($this->query, $this->parent);

        $this->parent->applyNestedSetScope($this->query); /* @phpstan-ignore method.notFound */

        if (! $this->andSelf) {
            $this->query->where(
                $this->related->qualifyColumn($this->parent->getKeyName()),
                '<>',
                $this->parent->getKey(),
            );
        }
    }

    /**
     * Apply an eager constraint for one parent model.
     */
    protected function addEagerConstraint(Builder $query, Model $model): void
    {
        $query->whereNested(function (Builder $query) use ($model): void {
            $this->whereParentId($query, $model);

            $model->applyNestedSetScope($query); /* @phpstan-ignore method.notFound */

            if (! $this->andSelf) {
                $query->where(
                    $model->qualifyColumn($model->getKeyName()),
                    '<>',
                    $model->getKey(),
                );
            }
        }, 'or');
    }

    /**
     * Group eager constraints by exact scope and parent.
     */
    protected function constrainEagerModels(Builder $query, array $models): void
    {
        if (count($models) === 1) {
            $this->addEagerConstraint($query, $models[0]);

            return;
        }

        $groups = [];

        foreach ($models as $model) {
            $scope = $this->scopeKey($model);
            $groups[$scope]['model'] ??= $model;
            $parentId = $model->getParentId();

            if ($parentId === null) {
                $groups[$scope]['has_root'] = true;
            } else {
                $groups[$scope]['parents'][$parentId] = $parentId;
            }
        }

        $this->addBalancedOrConstraints($query, array_values($groups), function (Builder $query, array $group): void {
            $query->whereNested(function (Builder $query) use ($group): void {
                $group['model']->applyNestedSetScope($query);

                $query->where(function (Builder $query) use ($group): void {
                    $parents = array_values($group['parents'] ?? []);
                    $hasRoot = $group['has_root'] ?? false;
                    $parentIdName = $group['model']->getParentIdName();
                    $qualifiedParentIdName = $group['model']->qualifyColumn($parentIdName);

                    if ($parents !== []) {
                        $query->whereIn($qualifiedParentIdName, $parents);
                    }

                    if ($hasRoot) {
                        $parents === []
                            ? $query->whereNull($qualifiedParentIdName)
                            : $query->orWhereNull($qualifiedParentIdName);
                    }
                });
            }, 'or');
        });
    }

    /**
     * Match siblings from exact scope-and-parent buckets in query order.
     */
    protected function matchMany(array $models, Collection $results): array
    {
        $index = [];
        $keys = [];

        foreach ($results as $related) {
            $scope = $this->scopeKey($related);
            $parentId = $related->getParentId(); /* @phpstan-ignore method.notFound */

            if ($parentId === null) {
                $index[$scope]['roots'][] = $related;
            } else {
                $index[$scope]['parents'][$parentId][] = $related;
            }

            if (! $this->andSelf) {
                $keys[spl_object_id($related)] = $this->matchKey($related);
            }
        }

        $matches = [];

        foreach ($models as $model) {
            $scope = $index[$this->scopeKey($model)] ?? null;

            if ($scope === null) {
                continue;
            }

            $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */
            $siblings = $parentId === null ? ($scope['roots'] ?? []) : ($scope['parents'][$parentId] ?? []);

            if ($this->andSelf) {
                // Parents in one bucket share its array until a collection modifies it.
                $matches[spl_object_id($model)] = $siblings;

                continue;
            }

            $key = $this->matchKey($model);
            $found = [];

            foreach ($siblings as $candidate) {
                if ($key === null || $keys[spl_object_id($candidate)] !== $key) {
                    $found[] = $candidate;
                }
            }

            $matches[spl_object_id($model)] = $found;
        }

        return $matches;
    }

    /**
     * Get the parent columns required to resolve siblings.
     */
    protected function requiredParentColumns(Model $model): array
    {
        return $this->siblingColumns($model);
    }

    /**
     * Get the related columns required to match siblings.
     */
    protected function requiredRelatedColumns(Model $model): array
    {
        return $this->siblingColumns($model);
    }

    /**
     * Get the complete sibling bucket projection.
     */
    protected function siblingColumns(Model $model): array
    {
        $columns = array_fill_keys(array_keys($this->scopeValues($model)), false);
        $columns[$model->getParentIdName()] = false; /* @phpstan-ignore method.notFound */

        if (! $this->andSelf) {
            $columns[$model->getKeyName()] = true;
        }

        return $columns;
    }

    /**
     * Get the sibling relation existence condition.
     */
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        $grammar = $this->getBaseQuery()->getGrammar();
        $parentId = $grammar->wrap($this->getForeignKeyName());
        $key = $grammar->wrap($this->parent->getKeyName());

        $condition = "({$hash}.{$parentId} = {$table}.{$parentId}"
            . " or ({$hash}.{$parentId} is null and {$table}.{$parentId} is null))";

        if (! $this->andSelf) {
            $condition .= " and {$hash}.{$key} <> {$table}.{$key}";
        }

        return $condition;
    }

    /**
     * Constrain a query to the model's parent ID.
     */
    protected function whereParentId(Builder|QueryBuilder $query, Model $model): void
    {
        $parentIdName = $model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $qualifiedParentIdName = $model->qualifyColumn($parentIdName);
        $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */

        $parentId === null
            ? $query->whereNull($qualifiedParentIdName)
            : $query->where($qualifiedParentIdName, '=', $parentId);
    }
}
