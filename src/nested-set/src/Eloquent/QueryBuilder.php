<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hyperf\Collection\Collection as HyperfCollection;
use Hyperf\Database\Model\Builder as EloquentBuilder;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\Database\Query\Builder as BaseQueryBuilder;
use Hyperf\Database\Query\Expression;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Arr;
use LogicException;

class QueryBuilder extends EloquentBuilder
{
    /**
     * Get node's `lft` and `rgt` values.
     */
    public function getNodeData(mixed $id, bool $required = false): array
    {
        $data = $this->toBase()
            ->where($this->model->getKeyName(), '=', $id)
            ->first([
                $this->model->getLftName(), /* @phpstan-ignore-line */
                $this->model->getRgtName(), /* @phpstan-ignore-line */
            ]);

        if (! $data && $required) {
            throw new ModelNotFoundException();
        }

        return (array) $data;
    }

    /**
     * Get plain node data.
     */
    public function getPlainNodeData(mixed $id, bool $required = false): array
    {
        return array_values($this->getNodeData($id, $required));
    }

    /**
     * Scope limits query to select just root node.
     */
    public function whereIsRoot(): static
    {
        /* @phpstan-ignore-next-line */
        $this->query->whereNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Limit results to ancestors of specified node.
     */
    public function whereAncestorOf(mixed $id, bool $andSelf = false, string $boolean = 'and'): static
    {
        $keyName = $this->model->getTable() . '.' . $this->model->getKeyName();
        $model = null;

        if (NestedSet::isNode($id)) {
            $model = $id;
            $value = '?';

            $this->query->addBinding($id->getRgt());

            $id = $id->getKey();
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_.' . $this->model->getRgtName()) /* @phpstan-ignore-line */
                ->from($this->model->getTable() . ' as _')
                ->where($this->model->getKeyName(), '=', $id)
                ->limit(1);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
        }

        $this->query->whereNested(function ($inner) use ($model, $value, $andSelf, $id, $keyName) {
            [$lft, $rgt] = $this->wrappedColumns();
            $wrappedTable = $this->query->getGrammar()->wrapTable($this->model->getTable());

            $inner->whereRaw("{$value} between {$wrappedTable}.{$lft} and {$wrappedTable}.{$rgt}");

            if (! $andSelf) {
                $inner->where($keyName, '<>', $id);
            }
            if ($model !== null) {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $model->applyNestedSetScope($inner);
            }
        }, $boolean);

        return $this;
    }

    public function orWhereAncestorOf(mixed $id, bool $andSelf = false): static
    {
        return $this->whereAncestorOf($id, $andSelf, 'or');
    }

    public function whereAncestorOrSelf(mixed $id): static
    {
        return $this->whereAncestorOf($id, true);
    }

    /**
     * Get ancestors of specified node.
     */
    public function ancestorsOf(mixed $id, array $columns = ['*']): HyperfCollection
    {
        /* @phpstan-ignore-next-line */
        return $this->whereAncestorOf($id)->get($columns);
    }

    public function ancestorsAndSelf(mixed $id, array $columns = ['*']): HyperfCollection
    {
        /* @phpstan-ignore-next-line */
        return $this->whereAncestorOf($id, true)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     */
    public function whereNodeBetween(array $values, string $boolean = 'and', bool $not = false, ?BaseQueryBuilder $query = null): static
    {
        /* @phpstan-ignore-next-line */
        ($query ?? $this->query)->whereBetween($this->model->getTable() . '.' . $this->model->getLftName(), $values, $boolean, $not);

        return $this;
    }

    /**
     * Add node selection statement between specified range joined with `or` operator.
     */
    public function orWhereNodeBetween(array $values): static
    {
        return $this->whereNodeBetween($values, 'or');
    }

    /**
     * Add constraint statement to descendants of specified node.
     */
    public function whereDescendantOf(mixed $id, string $boolean = 'and', bool $not = false, bool $andSelf = false): static
    {
        $this->query->whereNested(function (BaseQueryBuilder $inner) use ($id, $andSelf, $not) {
            if (NestedSet::isNode($id)) {
                $id->applyNestedSetScope($inner);
                $data = $id->getBounds();
            } else {
                // we apply scope only when Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                /* @phpstan-ignore-next-line */
                $data = $this->model->newNestedSetQuery()
                    ->getPlainNodeData($id, true);
            }

            // Don't include the node
            if (! $andSelf) {
                ++$data[0];
            }

            return $this->whereNodeBetween($data, 'and', $not, $inner);
        }, $boolean);

        return $this;
    }

    public function whereNotDescendantOf(mixed $id): QueryBuilder
    {
        return $this->whereDescendantOf($id, 'and', true);
    }

    public function orWhereDescendantOf(mixed $id): QueryBuilder
    {
        return $this->whereDescendantOf($id, 'or');
    }

    public function orWhereNotDescendantOf(mixed $id): QueryBuilder
    {
        return $this->whereDescendantOf($id, 'or', true);
    }

    public function whereDescendantOrSelf(mixed $id, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereDescendantOf($id, $boolean, $not, true);
    }

    /**
     * Get descendants of specified node.
     */
    public function descendantsOf(mixed $id, array $columns = ['*'], bool $andSelf = false): HyperfCollection
    {
        try {
            return $this->whereDescendantOf($id, 'and', false, $andSelf)->get($columns);
        } catch (ModelNotFoundException $e) {
            return $this->model->newCollection();
        }
    }

    public function descendantsAndSelf(mixed $id, array $columns = ['*']): HyperfCollection
    {
        return $this->descendantsOf($id, $columns, true);
    }

    protected function whereIsBeforeOrAfter(mixed $id, string $operator, string $boolean): static
    {
        if (NestedSet::isNode($id)) {
            $value = '?';

            $this->query->addBinding($id->getLft());
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_n.' . $this->model->getLftName()) /* @phpstan-ignore-line */
                ->from($this->model->getTable() . ' as _n')
                ->where('_n.' . $this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
        }

        [$lft] = $this->wrappedColumns();

        $this->query->whereRaw("{$lft} {$operator} {$value}", [], $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are after specified node.
     */
    public function whereIsAfter(mixed $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '>', $boolean);
    }

    /**
     * Constraint nodes to those that are before specified node.
     */
    public function whereIsBefore(mixed $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '<', $boolean);
    }

    public function whereIsLeaf(): QueryBuilder|BaseQueryBuilder
    {
        [$lft, $rgt] = $this->wrappedColumns();

        return $this->whereRaw("{$lft} = {$rgt} - 1");
    }

    public function leaves(array $columns = ['*']): HyperfCollection
    {
        return $this->whereIsLeaf()->get($columns);
    }

    /**
     * Include depth level into the result.
     */
    public function withDepth(string $as = 'depth'): static
    {
        if ($this->query->columns === null) {
            $this->query->columns = ['*'];
        }

        $table = $this->wrappedTable();

        [$lft, $rgt] = $this->wrappedColumns();

        $alias = '_d';
        $wrappedAlias = $this->query->getGrammar()->wrapTable($alias);

        /* @phpstan-ignore-next-line */
        $query = $this->model
            ->newScopedQuery('_d')
            ->toBase()
            ->selectRaw('count(1) - 1')
            ->from($this->model->getTable() . ' as ' . $alias)
            ->whereRaw("{$table}.{$lft} between {$wrappedAlias}.{$lft} and {$wrappedAlias}.{$rgt}");

        $this->query->selectSub($query, $as);

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     */
    protected function wrappedColumns(): array
    {
        $grammar = $this->query->getGrammar();

        return [
            $grammar->wrap($this->model->getLftName()), /* @phpstan-ignore-line */
            $grammar->wrap($this->model->getRgtName()), /* @phpstan-ignore-line */
        ];
    }

    /**
     * Get a wrapped table name.
     */
    protected function wrappedTable(): string
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }

    /**
     * Wrap model's key name.
     */
    protected function wrappedKey(): string
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }

    /**
     * Exclude root node from the result.
     */
    public function withoutRoot(): static
    {
        /* @phpstan-ignore-next-line */
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Equivalent of `withoutRoot`.
     */
    public function hasParent(): static
    {
        /* @phpstan-ignore-next-line */
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Get only nodes that have children.
     */
    public function hasChildren(): static
    {
        [$lft, $rgt] = $this->wrappedColumns();

        $this->query->whereRaw("{$rgt} > {$lft} + 1");

        return $this;
    }

    /**
     * Order by node position.
     */
    public function defaultOrder(string $dir = 'asc'): static
    {
        /* @phpstan-ignore-next-line */
        $this->query->orders = null;

        $this->query->orderBy($this->model->getLftName(), $dir); /* @phpstan-ignore-line */

        return $this;
    }

    /**
     * Order by reversed node position.
     */
    public function reversed(): static
    {
        return $this->defaultOrder('desc');
    }

    /**
     * Move a node to the new position.
     */
    public function moveNode(mixed $key, int $position): int
    {
        /* @phpstan-ignore-next-line */
        [$lft, $rgt] = $this->model->newNestedSetQuery()
            ->getPlainNodeData($key, true);

        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        // If no distance to travel, just return
        if ($distance === 0) {
            return 0;
        }

        if ($position > $lft) {
            $height *= -1;
        } else {
            $distance *= -1;
        }

        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');

        $boundary = [$from, $to];

        $query = $this->toBase()->where(function (BaseQueryBuilder $inner) use ($boundary) {
            $inner->whereBetween($this->model->getLftName(), $boundary); /* @phpstan-ignore-line */
            $inner->orWhereBetween($this->model->getRgtName(), $boundary); /* @phpstan-ignore-line */
        });

        return $query->update($this->patch($params));
    }

    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     */
    public function makeGap(int $cut, int $height): int
    {
        $params = compact('cut', 'height');

        $query = $this->toBase()->whereNested(function (BaseQueryBuilder $inner) use ($cut) {
            $inner->where($this->model->getLftName(), '>=', $cut); /* @phpstan-ignore-line */
            $inner->orWhere($this->model->getRgtName(), '>=', $cut); /* @phpstan-ignore-line */
        });

        return $query->update($this->patch($params));
    }

    /**
     * Get patch for columns.
     */
    protected function patch(array $params): array
    {
        $grammar = $this->query->getGrammar();

        $columns = [];

        /* @phpstan-ignore-next-line */
        foreach ([$this->model->getLftName(), $this->model->getRgtName()] as $col) {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get patch for single column.
     */
    protected function columnPatch(string $col, array $params): Expression
    {
        extract($params);

        /** @var int $height */
        if ($height > 0) {
            $height = " + {$height}";
        }

        if (isset($cut)) {
            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }

        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance > 0) {
            $distance = " + {$distance}";
        }

        return new Expression(
            'case '
                . "when {$col} between {$lft} and {$rgt} then {$col}{$distance} " // Move the node
                . "when {$col} between {$from} and {$to} then {$col}{$height} " // Move other nodes
                . "else {$col} end"
        );
    }

    /**
     * Get statistics of errors of the tree.
     */
    public function countErrors(): array
    {
        $checks = [];

        // Check if lft and rgt values are ok
        $checks['oddness'] = $this->getOdnessQuery();

        // Check if lft and rgt values are unique
        $checks['duplicates'] = $this->getDuplicatesQuery();

        // Check if parent_id is set correctly
        $checks['wrong_parent'] = $this->getWrongParentQuery();

        // Check for nodes that have missing parent
        $checks['missing_parent'] = $this->getMissingParentQuery();

        $query = $this->query->newQuery();

        foreach ($checks as $key => $inner) {
            $inner->selectRaw('count(1)');

            $query->selectSub($inner, $key);
        }

        return (array) $query->first();
    }

    protected function getOdnessQuery(): BaseQueryBuilder
    {
        /* @phpstan-ignore-next-line */
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (BaseQueryBuilder $inner) {
                [$lft, $rgt] = $this->wrappedColumns();

                $inner->whereRaw("{$lft} >= {$rgt}")
                    ->orWhereRaw("({$rgt} - {$lft}) % 2 = 0");
            });
    }

    protected function getDuplicatesQuery(): BaseQueryBuilder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $firstAlias = 'c1';
        $secondAlias = 'c2';

        $waFirst = $this->query->getGrammar()->wrapTable($firstAlias);
        $waSecond = $this->query->getGrammar()->wrapTable($secondAlias);

        /* @phpstan-ignore-next-line */
        $query = $this->model
            ->newNestedSetQuery($firstAlias)
            ->toBase()
            ->from($this->query->raw("{$table} as {$waFirst}, {$table} {$waSecond}"))
            ->whereRaw("{$waFirst}.{$keyName} < {$waSecond}.{$keyName}")
            ->whereNested(function (BaseQueryBuilder $inner) use ($waFirst, $waSecond) {
                [$lft, $rgt] = $this->wrappedColumns();

                $inner->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$lft}")
                    ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$rgt}")
                    ->orWhereRaw("{$waFirst}.{$lft}={$waSecond}.{$rgt}")
                    ->orWhereRaw("{$waFirst}.{$rgt}={$waSecond}.{$lft}");
            });

        /* @phpstan-ignore-next-line */
        return $this->model->applyNestedSetScope($query, $secondAlias);
    }

    protected function getWrongParentQuery(): BaseQueryBuilder
    {
        $table = $this->wrappedTable();
        $keyName = $this->wrappedKey();

        $grammar = $this->query->getGrammar();

        /* @phpstan-ignore-next-line */
        $parentIdName = $grammar->wrap($this->model->getParentIdName());

        $parentAlias = 'p';
        $childAlias = 'c';
        $intermAlias = 'i';

        $waParent = $grammar->wrapTable($parentAlias);
        $waChild = $grammar->wrapTable($childAlias);
        $waInterm = $grammar->wrapTable($intermAlias);

        /* @phpstan-ignore-next-line */
        $query = $this->model
            ->newNestedSetQuery('c')
            ->toBase()
            ->from($this->query->raw("{$table} as {$waChild}, {$table} as {$waParent}, {$table} as {$waInterm}"))
            ->whereRaw("{$waChild}.{$parentIdName}={$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waParent}.{$keyName}")
            ->whereRaw("{$waInterm}.{$keyName} <> {$waChild}.{$keyName}")
            ->whereNested(function (BaseQueryBuilder $inner) use ($waInterm, $waChild, $waParent) {
                [$lft, $rgt] = $this->wrappedColumns();

                $inner->whereRaw("{$waChild}.{$lft} not between {$waParent}.{$lft} and {$waParent}.{$rgt}")
                    ->orWhereRaw("{$waChild}.{$lft} between {$waInterm}.{$lft} and {$waInterm}.{$rgt}")
                    ->whereRaw("{$waInterm}.{$lft} between {$waParent}.{$lft} and {$waParent}.{$rgt}");
            });

        /* @phpstan-ignore-next-line */
        $this->model->applyNestedSetScope($query, $parentAlias);
        /* @phpstan-ignore-next-line */
        $this->model->applyNestedSetScope($query, $intermAlias);

        return $query;
    }

    protected function getMissingParentQuery(): BaseQueryBuilder
    {
        /* @phpstan-ignore-next-line */
        return $this->model
            ->newNestedSetQuery()
            ->toBase()
            ->whereNested(function (BaseQueryBuilder $inner) {
                $grammar = $this->query->getGrammar();

                $table = $this->wrappedTable();
                $keyName = $this->wrappedKey();
                $parentIdName = $grammar->wrap($this->model->getParentIdName()); /* @phpstan-ignore-line */
                $alias = 'p';
                $wrappedAlias = $grammar->wrapTable($alias);

                /* @phpstan-ignore-next-line */
                $existsCheck = $this->model
                    ->newNestedSetQuery()
                    ->toBase()
                    ->selectRaw('1')
                    ->from($this->query->raw("{$table} as {$wrappedAlias}"))
                    ->whereRaw("{$table}.{$parentIdName} = {$wrappedAlias}.{$keyName}")
                    ->limit(1);

                /* @phpstan-ignore-next-line */
                $this->model->applyNestedSetScope($existsCheck, $alias);

                $inner->whereRaw("{$parentIdName} is not null")
                    ->addWhereExistsQuery($existsCheck, 'and', true);
            });
    }

    /**
     * Get the number of total errors of the tree.
     */
    public function getTotalErrors(): int
    {
        return array_sum($this->countErrors());
    }

    /**
     * Get whether the tree is broken.
     */
    public function isBroken(): bool
    {
        return $this->getTotalErrors() > 0;
    }

    /**
     * Fixes the tree based on parentage info.
     * Nodes with invalid parent are saved as roots.
     */
    public function fixTree(?Model $root = null): int
    {
        $columns = [
            $this->model->getKeyName(), /* @phpstan-ignore-line */
            $this->model->getParentIdName(), /* @phpstan-ignore-line */
            $this->model->getLftName(), /* @phpstan-ignore-line */
            $this->model->getRgtName(), /* @phpstan-ignore-line */
        ];

        /* @phpstan-ignore-next-line */
        $dictionary = $this->model
            ->newNestedSetQuery()
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->defaultOrder()
            ->get($columns)
            ->groupBy($this->model->getParentIdName()) /* @phpstan-ignore-line */
            ->all();

        return $this->fixNodes($dictionary, $root);
    }

    public function fixSubtree(Model $root): int
    {
        return $this->fixTree($root);
    }

    protected function fixNodes(array &$dictionary, ?Model $parent = null): int
    {
        $parentId = $parent ? $parent->getKey() : null;
        $cut = $parent ? $parent->getLft() + 1 : 1; /* @phpstan-ignore-line */

        $updated = [];
        $moved = 0;

        $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);

        // Save nodes that have invalid parent as roots
        while (! empty($dictionary)) {
            $dictionary[null] = reset($dictionary);

            unset($dictionary[key($dictionary)]);

            $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);
        }

        /* @phpstan-ignore-next-line */
        if ($parent && ($grown = $cut - $parent->getRgt()) != 0) {
            /* @phpstan-ignore-next-line */
            $moved = $this->model->newScopedQuery()->makeGap($parent->getRgt() + 1, $grown);

            /* @phpstan-ignore-next-line */
            $updated[] = $parent->rawNode($parent->getLft(), $cut, $parent->getParentId());
        }

        foreach ($updated as $model) {
            $model->save();
        }

        return count($updated) + $moved;
    }

    protected static function reorderNodes(
        array &$dictionary,
        array &$updated,
        mixed $parentId = null,
        int $cut = 1
    ): int {
        if (! isset($dictionary[$parentId])) {
            return $cut;
        }

        foreach ($dictionary[$parentId] as $model) {
            $lft = $cut;
            /* @phpstan-ignore-next-line */
            $cut = static::reorderNodes($dictionary, $updated, $model->getKey(), $cut + 1);
            /* @phpstan-ignore-next-line */
            if ($model->rawNode($lft, $cut, $parentId)->isDirty()) {
                $updated[] = $model;
            }

            ++$cut;
        }

        unset($dictionary[$parentId]);

        return $cut;
    }

    /**
     * Rebuild the tree based on raw data.
     * If item data does not contain primary key, new node will be created.
     *
     * @param bool $delete whether to delete nodes that exists but not in the data array
     */
    public function rebuildTree(array $data, bool $delete = false, null|int|Model $root = null): int
    {
        /* @phpstan-ignore-next-line */
        if ($this->model->usesSoftDelete()) {
            /* @phpstan-ignore-next-line */
            $this->withTrashed();
        }

        $existing = $this
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->get()
            ->getDictionary();

        $dictionary = [];
        $parentId = $root ? $root->getKey() : null;

        $this->buildRebuildDictionary($dictionary, $data, $existing, $parentId);

        if (! empty($existing)) {
            /* @phpstan-ignore-next-line */
            if ($delete && ! $this->model->usesSoftDelete()) {
                /* @phpstan-ignore-next-line */
                $this->model
                    ->newScopedQuery()
                    ->whereIn($this->model->getKeyName(), array_keys($existing))
                    ->delete();
            } else {
                foreach ($existing as $model) {
                    $dictionary[$model->getParentId()][] = $model;

                    /* @phpstan-ignore-next-line */
                    if ($delete && $this->model->usesSoftDelete()
                        && ! $model->{$model->getDeletedAtColumn()}
                    ) {
                        $time = $this->model->fromDateTime($this->model->freshTimestamp());

                        $model->{$model->getDeletedAtColumn()} = $time;
                    }
                }
            }
        }

        return $this->fixNodes($dictionary, $root);
    }

    public function rebuildSubtree(mixed $root, array $data, bool $delete = false): int
    {
        return $this->rebuildTree($data, $delete, $root);
    }

    protected function buildRebuildDictionary(
        array &$dictionary,
        array $data,
        array &$existing,
        mixed $parentId = null
    ): void {
        $keyName = $this->model->getKeyName();

        foreach ($data as $itemData) {
            if (! isset($itemData[$keyName])) {
                $model = $this->model->newInstance($this->model->getAttributes());

                // Set some values that will be fixed later
                /* @phpstan-ignore-next-line */
                $model->rawNode(0, 0, $parentId);
            } else {
                if (! isset($existing[$key = $itemData[$keyName]])) {
                    throw new ModelNotFoundException();
                }

                $model = $existing[$key];

                // Disable any tree actions
                $model->rawNode($model->getLft(), $model->getRgt(), $parentId);

                unset($existing[$key]);
            }

            $model->fill(Arr::except($itemData, 'children'))->save();

            $dictionary[$parentId][] = $model;

            if (! isset($itemData['children'])) {
                continue;
            }

            $this->buildRebuildDictionary(
                $dictionary,
                $itemData['children'],
                $existing,
                $model->getKey()
            );
        }
    }

    public function applyNestedSetScope(?string $table = null): static
    {
        /* @phpstan-ignore-next-line */
        return $this->model->applyNestedSetScope($this, $table);
    }

    /**
     * Get the root node.
     */
    public function root(array $columns = ['*']): ?Model
    {
        return $this->whereIsRoot()->first($columns);
    }
}
