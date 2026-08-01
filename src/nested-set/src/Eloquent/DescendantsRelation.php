<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;

class DescendantsRelation extends BaseRelation
{
    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (! static::shouldAddConstraints()) {
            return;
        }

        if (! $this->hasLoadedBounds($this->parent) || ! $this->hasConcreteScope($this->parent)) {
            $this->query->whereRaw('0 = 1');

            return;
        }

        $this->query->whereDescendantOf($this->parent);
    }

    /**
     * Add an eager descendant constraint.
     */
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereDescendantOf($model);
    }

    /**
     * Remove parent intervals whose descendant queries are already covered.
     */
    protected function prepareEagerModels(array $models): array
    {
        $groups = [];

        foreach (parent::prepareEagerModels($models) as $model) {
            if (! $this->hasLoadedBounds($model) || ! $this->hasConcreteScope($model)) {
                continue;
            }

            $groups[$this->scopeKey($model)][] = $model;
        }

        $result = [];

        foreach ($groups as $group) {
            usort($group, function (Model $left, Model $right): int {
                $comparison = ($left->getLft() ?? PHP_INT_MAX) /* @phpstan-ignore method.notFound */
                    <=> ($right->getLft() ?? PHP_INT_MAX); /* @phpstan-ignore method.notFound */

                return $comparison !== 0
                    ? $comparison
                    : ($right->getRgt() ?? PHP_INT_MIN) /* @phpstan-ignore method.notFound */
                        <=> ($left->getRgt() ?? PHP_INT_MIN); /* @phpstan-ignore method.notFound */
            });

            $maximumRgt = null;

            foreach ($group as $model) {
                $rgt = $model->getRgt(); /* @phpstan-ignore method.notFound */

                if ($rgt !== null && $maximumRgt !== null && $rgt <= $maximumRgt) {
                    continue;
                }

                $result[] = $model;

                if ($rgt !== null) {
                    $maximumRgt = $maximumRgt === null ? $rgt : max($maximumRgt, $rgt);
                }
            }
        }

        return $result;
    }

    /**
     * Determine whether a node is a descendant of the parent.
     */
    protected function matches(Model $model, Model $related): bool
    {
        /* @phpstan-ignore method.notFound */
        return $related->isDescendantOf($model);
    }

    /**
     * Index descendants by exact scope and left-bound order.
     */
    protected function indexResults(Collection $results): array
    {
        /** @var array<string, array{models: list<Model>, lfts: list<?int>, monotonic: bool, last_lft: ?int}> $indexed */
        $indexed = [];

        foreach ($results as $related) {
            $scope = $this->scopeKey($related);
            $lft = $related->getLft(); /* @phpstan-ignore method.notFound */

            if (! isset($indexed[$scope])) {
                $indexed[$scope] = [
                    'models' => [],
                    'lfts' => [],
                    'monotonic' => true,
                    'last_lft' => null,
                ];
            }

            $bucket = &$indexed[$scope];

            if ($lft === null || ($bucket['last_lft'] !== null && $lft < $bucket['last_lft'])) {
                $bucket['monotonic'] = false;
            }

            $bucket['models'][] = $related;
            $bucket['lfts'][] = $lft;
            $bucket['last_lft'] = $lft;

            // Break the reference before the cleanup loop can advance it.
            unset($bucket);
        }

        foreach ($indexed as &$bucket) {
            unset($bucket['last_lft']);
        }

        return $indexed;
    }

    /**
     * Match descendants from an exact scope bucket.
     */
    protected function matchFromIndex(Model $model, array $indexed): Collection
    {
        $bucket = $indexed[$this->scopeKey($model)] ?? null;

        if ($bucket === null) {
            return $this->related->newCollection();
        }

        $lft = $model->getLft(); /* @phpstan-ignore method.notFound */
        $rgt = $model->getRgt(); /* @phpstan-ignore method.notFound */

        if ($lft === null || $rgt === null) {
            return $this->related->newCollection();
        }

        if (! $bucket['monotonic']) {
            return $this->matchForModel(
                $model,
                $this->related->newCollection($bucket['models']),
            );
        }

        $result = $this->related->newCollection();
        $start = static::lowerBound($bucket['lfts'], $lft + 1);

        for ($index = $start, $count = count($bucket['models']); $index < $count; ++$index) {
            if ($bucket['lfts'][$index] >= $rgt) {
                break;
            }

            $related = $bucket['models'][$index];

            if ($this->matches($model, $related)) {
                $result->push($related);
            }
        }

        return $result;
    }

    /**
     * Find the first sorted value greater than or equal to the given value.
     */
    protected static function lowerBound(array $values, int $needle): int
    {
        $low = 0;
        $high = count($values);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($values[$middle] < $needle) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * Get the descendant relation existence condition.
     */
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}
