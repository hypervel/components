<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder;

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

        if (! $this->prepareLazyParent()) {
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
     * Constrain the eager query to ancestors of the prepared parents.
     *
     * A node is an ancestor when the smallest parent right bound at or after
     * its left bound falls before its right bound. A balanced CASE finds that
     * bound in logarithmic steps per row, whereas one "or" condition per
     * parent costs every scanned row a comparison per parent.
     */
    protected function constrainEagerModels(Builder $query, array $models): void
    {
        $groups = [];

        foreach ($models as $model) {
            $groups[$this->scopeKey($model)][] = $model;
        }

        $grammar = $query->getGrammar();
        $lft = $grammar->wrap($this->related->qualifyColumn($this->related->getLftName())); /* @phpstan-ignore method.notFound */
        $rgt = $grammar->wrap($this->related->qualifyColumn($this->related->getRgtName())); /* @phpstan-ignore method.notFound */

        $this->addBalancedOrConstraints(
            $query,
            array_values($groups),
            function (Builder $query, array $group) use ($lft, $rgt): void {
                $points = array_values(array_unique(array_map(
                    static fn (Model $model): int => $model->getRgt(), /* @phpstan-ignore method.notFound */
                    $group,
                )));
                sort($points);

                $query->whereNested(function (Builder $query) use ($group, $points, $lft, $rgt): void {
                    $group[0]->applyNestedSetScope($query);

                    // Validated integer bounds are inlined, as whereIntegerInRaw() does,
                    // so large eager loads do not exhaust the driver's bound parameters.
                    $query->whereRaw(sprintf('%s <= %d', $lft, $points[count($points) - 1]))
                        ->whereRaw(sprintf('%s > %d', $rgt, $points[0]));

                    if (count($points) > 1) {
                        $query->whereRaw(sprintf(
                            '%s > %s',
                            $rgt,
                            $this->successorExpression($lft, $points, 0, count($points) - 1),
                        ));
                    }
                }, 'or');
            },
        );
    }

    /**
     * Build a balanced expression for the smallest point at or after the left bound.
     *
     * @param list<int> $points sorted parent right bounds
     */
    protected function successorExpression(string $lft, array $points, int $low, int $high): string
    {
        if ($low === $high) {
            return (string) $points[$low];
        }

        $middle = intdiv($low + $high, 2);

        return sprintf(
            'case when %s <= %d then %s else %s end',
            $lft,
            $points[$middle],
            $this->successorExpression($lft, $points, $low, $middle),
            $this->successorExpression($lft, $points, $middle + 1, $high),
        );
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
            $lfts = [];
            $rgts = [];

            foreach ($group as $model) {
                $lfts[] = $model->getLft(); /* @phpstan-ignore method.notFound */
                $rgts[] = $model->getRgt(); /* @phpstan-ignore method.notFound */
            }

            $positions = array_keys($group);

            // Positions are unique, so ties never compare the models.
            array_multisort($lfts, SORT_DESC, $rgts, $positions, $group);

            $minimumRgt = null;

            foreach ($group as $offset => $model) {
                $rgt = $rgts[$offset];

                if ($minimumRgt !== null && $rgt >= $minimumRgt) {
                    continue;
                }

                $result[] = $model;
                $minimumRgt = $minimumRgt === null ? $rgt : min($minimumRgt, $rgt);
            }
        }

        return $result;
    }

    /**
     * Match ancestors with one sweep per scope over results and parents in left-bound order.
     */
    protected function matchMany(array $models, Collection $results): array
    {
        if (count($models) === 1) {
            $model = $models[0];
            $scope = $this->scopeKey($model);
            $key = $this->matchKey($model);
            /** @var int $lft */
            $lft = $model->getLft(); /* @phpstan-ignore method.notFound */
            $found = [];

            foreach ($results as $related) {
                if ($related->getLft() < $lft /* @phpstan-ignore method.notFound */
                    && $related->getRgt() > $lft /* @phpstan-ignore method.notFound */
                    && $this->scopeKey($related) === $scope
                    && ($key === null || $this->matchKey($related) !== $key)
                ) {
                    $found[] = $related;
                }
            }

            return [spl_object_id($model) => $found];
        }

        $buckets = $this->sortedResultBuckets($results, true);
        $groups = [];

        foreach ($models as $model) {
            $groups[$this->scopeKey($model)][] = $model;
        }

        $matches = [];

        foreach ($groups as $scope => $group) {
            $bucket = $buckets[$scope] ?? null;

            if ($bucket === null) {
                continue;
            }

            $lfts = array_map(
                static fn (Model $model): int => $model->getLft(), /* @phpstan-ignore method.notFound */
                $group,
            );
            $positions = array_keys($group);

            // Positions are unique, so ties never compare the models.
            array_multisort($lfts, $positions, $group);

            $count = count($bucket['lfts']);
            $next = 0;
            $open = [];

            foreach ($group as $offset => $model) {
                $key = $this->matchKey($model);
                $lft = $lfts[$offset];

                // Each result enters the open stack once and leaves once it closes.
                for (; $next < $count && $bucket['lfts'][$next] < $lft; ++$next) {
                    while ($open !== [] && $bucket['rgts'][$open[count($open) - 1]] < $bucket['lfts'][$next]) {
                        array_pop($open);
                    }

                    $open[] = $next;
                }

                while ($open !== [] && $bucket['rgts'][$open[count($open) - 1]] <= $lft) {
                    array_pop($open);
                }

                $found = [];

                foreach ($open as $index) {
                    if ($bucket['rgts'][$index] > $lft
                        && ($key === null || $bucket['keys'][$index] !== $key)
                    ) {
                        $found[] = $index;
                    }
                }

                $matches[spl_object_id($model)] = $this->bucketModels($bucket, $found);
            }
        }

        return $matches;
    }

    /**
     * Get the parent columns required to resolve ancestors.
     */
    protected function requiredParentColumns(Model $model): array
    {
        // Eager reduction and matching use both bounds even though the lazy
        // ancestor predicate only consumes the right bound.
        return $this->intervalColumns($model);
    }

    /**
     * Get the related columns required to match ancestors.
     */
    protected function requiredRelatedColumns(Model $model): array
    {
        return $this->intervalColumns($model);
    }

    /**
     * Get the complete interval and scope projection.
     */
    protected function intervalColumns(Model $model): array
    {
        $columns = array_fill_keys(array_keys($this->scopeValues($model)), false);
        $columns[$model->getLftName()] = true; /* @phpstan-ignore method.notFound */
        $columns[$model->getRgtName()] = true; /* @phpstan-ignore method.notFound */

        return $columns;
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
