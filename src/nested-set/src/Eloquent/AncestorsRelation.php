<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Model;

class AncestorsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! static::shouldAddConstraints()) {
            return;
        }

        $this->query->whereAncestorOf($this->parent)
            ->defaultOrder();
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->query->defaultOrder();
    }

    /**
     * Determine whether a node is an ancestor of the parent.
     */
    protected function matches(Model $model, Model $related): bool
    {
        /* @phpstan-ignore method.notFound */
        return $related->isAncestorOf($model);
    }

    /**
     * Add an eager ancestor constraint.
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereAncestorOf($model);
    }

    /**
     * Remove parent intervals whose ancestor queries are already covered.
     */
    protected function prepareEagerModels(array $models): array
    {
        $groups = [];

        foreach (parent::prepareEagerModels($models) as $model) {
            $groups[$this->scopeKey($model)][] = $model;
        }

        $result = [];

        foreach ($groups as $group) {
            usort($group, function (Model $left, Model $right): int {
                $comparison = ($right->getLft() ?? PHP_INT_MIN) /* @phpstan-ignore method.notFound */
                    <=> ($left->getLft() ?? PHP_INT_MIN); /* @phpstan-ignore method.notFound */

                return $comparison !== 0
                    ? $comparison
                    : ($left->getRgt() ?? PHP_INT_MAX) /* @phpstan-ignore method.notFound */
                        <=> ($right->getRgt() ?? PHP_INT_MAX); /* @phpstan-ignore method.notFound */
            });

            $minimumRgt = null;

            foreach ($group as $model) {
                $rgt = $model->getRgt(); /* @phpstan-ignore method.notFound */

                if ($rgt !== null && $minimumRgt !== null && $rgt >= $minimumRgt) {
                    continue;
                }

                $result[] = $model;

                if ($rgt !== null) {
                    $minimumRgt = $minimumRgt === null ? $rgt : min($minimumRgt, $rgt);
                }
            }
        }

        return $result;
    }

    /**
     * Index only when multiple concrete scopes would otherwise be scanned.
     */
    protected function shouldIndexResults(array $models): bool
    {
        if (count($models) < 2) {
            return false;
        }

        $scope = $this->scopeKey($models[0]);

        foreach (array_slice($models, 1) as $model) {
            if ($this->scopeKey($model) !== $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the ancestor relation existence condition.
     */
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} and {$table}.{$key} <> {$hash}.{$key}";
    }
}
