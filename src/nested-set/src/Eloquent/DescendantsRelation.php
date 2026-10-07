<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder;

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

        if (! $this->prepareLazyParent()) {
            return;
        }

        $this->query->whereDescendantOf($this->parent);
    }

    /**
     * Constrain the eager query to descendants of the prepared parents.
     */
    protected function constrainEagerModels(Builder $query, array $models): void
    {
        $this->addBalancedOrConstraints($query, $models, $this->addEagerConstraint(...));
    }

    /**
     * Add an eager descendant constraint.
     */
    protected function addEagerConstraint(Builder $query, Model $model): void
    {
        $query->whereNested(function (Builder $query) use ($model): void {
            $model->applyNestedSetScope($query); /* @phpstan-ignore method.notFound */

            // Validated integer bounds are inlined, as whereIntegerInRaw() does,
            // so large eager loads do not exhaust the driver's bound parameters.
            $query->whereRaw(sprintf(
                '%s between %d and %d',
                $query->getGrammar()->wrap($this->related->qualifyColumn($this->related->getLftName())), /* @phpstan-ignore method.notFound */
                $model->getLft() + 1, /* @phpstan-ignore method.notFound */
                $model->getRgt(), /* @phpstan-ignore method.notFound */
            ));
        }, 'or');
    }

    /**
     * Remove empty parent intervals and those whose descendant queries are already covered.
     */
    protected function prepareEagerModels(array $models): array
    {
        $groups = [];

        foreach (parent::prepareEagerModels($models) as $model) {
            // Without a bound strictly between the parent's bounds, no node can be its descendant.
            if ($model->getRgt() /* @phpstan-ignore method.notFound */
                <= $model->getLft() + 1 /* @phpstan-ignore method.notFound */
            ) {
                continue;
            }

            $groups[$this->scopeKey($model)][] = $model;
        }

        $result = [];

        foreach ($groups as $group) {
            $lfts = [];
            $rgts = [];

            foreach ($group as $model) {
                $lfts[] = $model->getLft(); /* @phpstan-ignore method.notFound */
                $rgts[] = $model->getRgt(); /* @phpstan-ignore method.notFound */
            }

            $positions = array_keys($group);

            // Positions are unique, so ties never compare the models.
            array_multisort($lfts, $rgts, SORT_DESC, $positions, $group);

            $maximumRgt = null;

            foreach ($group as $offset => $model) {
                $rgt = $rgts[$offset];

                if ($maximumRgt !== null && $rgt <= $maximumRgt) {
                    continue;
                }

                $result[] = $model;
                $maximumRgt = $maximumRgt === null ? $rgt : max($maximumRgt, $rgt);
            }
        }

        return $result;
    }

    /**
     * Match descendants by binary search over each scope's left bounds.
     */
    protected function matchMany(array $models, Collection $results): array
    {
        if (count($models) === 1) {
            $model = $models[0];
            $scope = $this->scopeKey($model);
            $key = $this->matchKey($model);
            /** @var int $lft */
            $lft = $model->getLft(); /* @phpstan-ignore method.notFound */
            /** @var int $rgt */
            $rgt = $model->getRgt(); /* @phpstan-ignore method.notFound */
            $found = [];

            foreach ($results as $related) {
                $relatedLft = $related->getLft(); /* @phpstan-ignore method.notFound */

                if ($relatedLft > $lft
                    && $relatedLft < $rgt
                    && $this->scopeKey($related) === $scope
                    && ($key === null || $this->matchKey($related) !== $key)
                ) {
                    $found[] = $related;
                }
            }

            return [spl_object_id($model) => $found];
        }

        $buckets = $this->sortedResultBuckets($results, false);
        $matches = [];

        foreach ($models as $model) {
            $bucket = $buckets[$this->scopeKey($model)] ?? null;

            if ($bucket === null) {
                continue;
            }

            $key = $this->matchKey($model);
            /** @var int $rgt */
            $rgt = $model->getRgt(); /* @phpstan-ignore method.notFound */
            $found = [];
            $index = static::lowerBound($bucket['lfts'], $model->getLft() + 1); /* @phpstan-ignore method.notFound */

            for ($count = count($bucket['lfts']); $index < $count && $bucket['lfts'][$index] < $rgt; ++$index) {
                if ($key === null || $bucket['keys'][$index] !== $key) {
                    $found[] = $index;
                }
            }

            $matches[spl_object_id($model)] = $this->bucketModels($bucket, $found);
        }

        return $matches;
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
     * Get the parent columns required to resolve descendants.
     */
    protected function requiredParentColumns(Model $model): array
    {
        $columns = $this->scopeColumns($model);
        $columns[$model->getLftName()] = true; /* @phpstan-ignore method.notFound */
        $columns[$model->getRgtName()] = true; /* @phpstan-ignore method.notFound */

        return $columns;
    }

    /**
     * Get the related columns required to match descendants.
     */
    protected function requiredRelatedColumns(Model $model): array
    {
        $columns = $this->scopeColumns($model);
        $columns[$model->getLftName()] = true; /* @phpstan-ignore method.notFound */

        return $columns;
    }

    /**
     * Get the scope projection required for matching.
     */
    protected function scopeColumns(Model $model): array
    {
        return array_fill_keys(array_keys($this->scopeValues($model)), false);
    }

    /**
     * Get the descendant relation existence condition.
     */
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}
