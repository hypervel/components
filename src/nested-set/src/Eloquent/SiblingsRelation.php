<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use LogicException;

class SiblingsRelation extends BaseRelation
{
    /**
     * Whether to include the parent node itself.
     */
    protected bool $andSelf;

    /**
     * Create a new sibling relation.
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

        if (! $this->canResolveSiblings($this->parent)) {
            $this->query->whereRaw('0 = 1');

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
     * Remove parents that cannot identify a concrete sibling bucket.
     */
    protected function prepareEagerModels(array $models): array
    {
        $eligible = [];

        foreach ($models as $model) {
            if ($this->canResolveSiblings($model)) {
                $eligible[] = $model;
            }
        }

        return parent::prepareEagerModels($eligible);
    }

    /**
     * Apply an eager constraint for one parent model.
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhere(function (QueryBuilder $query) use ($model) {
            $this->whereParentId($query, $model);

            $model->applyNestedSetScope($query); /* @phpstan-ignore method.notFound */

            if (! $this->andSelf) {
                $query->where(
                    $model->qualifyColumn($model->getKeyName()),
                    '<>',
                    $model->getKey(),
                );
            }
        });
    }

    /**
     * Group eager constraints by exact scope and parent.
     */
    protected function constrainEagerModels(QueryBuilder $query, array $models): void
    {
        if (count($models) === 1) {
            $this->addEagerConstraint($query, $models[0]);

            return;
        }

        $groups = [];

        foreach ($models as $model) {
            $scope = $this->scopeKey($model);
            $groups[$scope]['model'] ??= $model;
            $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */

            if ($parentId === null) {
                $groups[$scope]['has_root'] = true;
            } else {
                $groups[$scope]['parents'][$parentId] = $parentId;
            }
        }

        foreach ($groups as $group) {
            $query->orWhere(function (QueryBuilder $query) use ($group) {
                $group['model']->applyNestedSetScope($query); /* @phpstan-ignore method.notFound */

                $query->where(function (QueryBuilder $query) use ($group) {
                    $parents = array_values($group['parents'] ?? []);
                    $hasRoot = $group['has_root'] ?? false;
                    $parentIdName = $group['model']->getParentIdName(); /* @phpstan-ignore method.notFound */
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
            });
        }
    }

    /**
     * Determine whether a result is a sibling of the parent model.
     */
    protected function matches(Model $model, Model $related): bool
    {
        if ($this->scopeKey($model) !== $this->scopeKey($related)) {
            return false;
        }

        $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */
        $relatedParentId = $this->getRelatedParentIdForMatching($related);
        $sameParent = $parentId === null || $relatedParentId === null
            ? $parentId === $relatedParentId
            : (string) $parentId === (string) $relatedParentId;

        if (! $sameParent || $this->andSelf) {
            return $sameParent;
        }

        $key = $model->getKey();
        $relatedKey = $this->getRelatedKeyForMatching($related);

        // Strict parents without a key are removed before the eager query, so
        // this matcher is only reached with a concrete source key.
        return (string) $key !== (string) $relatedKey;
    }

    /**
     * Index eager results by exact scope and parent while preserving query order.
     */
    protected function indexResults(Collection $results): array
    {
        $index = [];

        foreach ($results as $related) {
            $scope = $this->scopeKey($related);
            $parentId = $this->getRelatedParentIdForMatching($related);

            if ($parentId === null) {
                $index[$scope]['roots'][] = $related;
            } else {
                $index[$scope]['parents'][$parentId][] = $related;
            }
        }

        return $index;
    }

    /**
     * Match siblings from an exact scope-and-parent bucket.
     */
    protected function matchFromIndex(Model $model, array $indexed): Collection
    {
        if (! $this->canResolveSiblings($model)) {
            return $this->related->newCollection();
        }

        $scope = $indexed[$this->scopeKey($model)] ?? null;

        if ($scope === null) {
            return $this->related->newCollection();
        }

        $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */
        $candidates = $parentId === null
            ? ($scope['roots'] ?? [])
            : ($scope['parents'][$parentId] ?? []);

        if ($this->andSelf) {
            return $this->related->newCollection($candidates);
        }

        $key = $model->getKey();
        $matches = [];

        foreach ($candidates as $candidate) {
            $candidateKey = $this->getRelatedKeyForMatching($candidate);

            if ((string) $candidateKey !== (string) $key) {
                $matches[] = $candidate;
            }
        }

        return $this->related->newCollection($matches);
    }

    /**
     * Determine whether a model has enough information to resolve its siblings.
     */
    protected function canResolveSiblings(Model $model): bool
    {
        return $this->hasLoadedParent($model)
            && $this->hasConcreteScope($model)
            && ($this->andSelf || $model->getKey() !== null);
    }

    /**
     * Get the related parent ID required for sibling matching.
     */
    protected function getRelatedParentIdForMatching(Model $related): int|string|null
    {
        if (! $this->hasLoadedParent($related)) {
            throw new LogicException(sprintf(
                'Nested set sibling matching for [%s] requires the [%s] column in the eager load projection.',
                $related::class,
                $related->getParentIdName(), /* @phpstan-ignore method.notFound */
            ));
        }

        return $related->getParentId(); /* @phpstan-ignore method.notFound */
    }

    /**
     * Get the related key required for strict sibling matching.
     */
    protected function getRelatedKeyForMatching(Model $related): int|string
    {
        /** @var null|int|string $key */
        $key = $related->getKey();

        if ($key === null) {
            throw new LogicException(sprintf(
                'Nested set sibling matching for [%s] requires the [%s] column in the eager load projection.',
                $related::class,
                $related->getKeyName(),
            ));
        }

        return $key;
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
    protected function whereParentId(QueryBuilder $query, Model $model): void
    {
        $parentIdName = $model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $qualifiedParentIdName = $model->qualifyColumn($parentIdName);
        $parentId = $model->getParentId(); /* @phpstan-ignore method.notFound */

        $parentId === null
            ? $query->whereNull($qualifiedParentIdName)
            : $query->where($qualifiedParentIdName, '=', $parentId);
    }
}
