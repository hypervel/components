<?php

declare(strict_types=1);

namespace Hypervel\NestedSet\Eloquent;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Query\Builder as BaseQueryBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\JoinClause;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\Collection as BaseCollection;
use InvalidArgumentException;
use LogicException;
use stdClass;

class QueryBuilder extends EloquentBuilder
{
    /**
     * Get a node's structural values.
     */
    public function getNodeData(int|string $id, bool $required = false): array
    {
        $this->ensureConcreteNestedSetScope('node lookup');

        $lftName = $this->model->getLftName(); /* @phpstan-ignore method.notFound */
        $rgtName = $this->model->getRgtName(); /* @phpstan-ignore method.notFound */
        $depthName = $this->model->getDepthName(); /* @phpstan-ignore method.notFound */

        // first() ignores its columns once a global scope has selected some,
        // so select() replaces any scope projection with the structural columns.
        $data = $this->toBase()
            ->select($this->model->qualifyColumns([$lftName, $rgtName, $depthName]))
            ->where($this->model->getQualifiedKeyName(), '=', $id)
            ->first();

        if (! $data && $required) {
            throw (new ModelNotFoundException)->setModel($this->model::class, [$id]);
        }

        return $data ? (array) $data : [];
    }

    /**
     * Get plain node data.
     */
    public function getPlainNodeData(int|string $id, bool $required = false): array
    {
        $data = $this->getNodeData($id, $required);

        return [
            $data[$this->model->getLftName()] ?? 0, /* @phpstan-ignore method.notFound */
            $data[$this->model->getRgtName()] ?? 0, /* @phpstan-ignore method.notFound */
        ];
    }

    /**
     * Scope limits query to select just root node.
     */
    public function whereIsRoot(): static
    {
        $this->query->whereNull($this->model->qualifyColumn($this->model->getParentIdName())); /* @phpstan-ignore method.notFound */

        return $this;
    }

    /**
     * Limit results to ancestors of specified node.
     */
    public function whereAncestorOf(Model|int|string $id, bool $andSelf = false, string $boolean = 'and'): static
    {
        $model = null;

        if ($id instanceof Model) {
            $model = $id;
            $this->ensureUsableNodeForPositionalQuery($model);
            $value = '?';
            $bindings = [$id->getRgt()]; /* @phpstan-ignore method.notFound */
        } else {
            $this->ensureConcreteNestedSetScope('scalar lookup');

            $valueQuery = $this->model
                ->newNestedSetQuery('_n') /* @phpstan-ignore method.notFound */
                ->toBase()
                ->select('_n.' . $this->model->getRgtName()) /* @phpstan-ignore method.notFound */
                ->from($this->model->getTable() . ' as _n')
                ->where('_n.' . $this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
            $bindings = [];
        }

        $this->query->whereNested(function ($inner) use ($model, $value, $bindings, $andSelf, $id) {
            [$lft, $rgt] = $this->wrappedColumns();

            $inner->whereRaw("{$value} between {$lft} and {$rgt}", $bindings);

            if (! $andSelf) {
                if ($model === null) {
                    $inner->where(
                        $this->model->qualifyColumn($this->model->getKeyName()),
                        '<>',
                        $id,
                    );
                } else {
                    $inner->whereRaw("{$rgt} > ?", [$model->getRgt()]); /* @phpstan-ignore method.notFound */
                }
            }
            if ($model !== null) {
                $model->applyNestedSetScope($inner); /* @phpstan-ignore method.notFound */
            }
        }, $boolean);

        return $this;
    }

    /**
     * Add an `or` constraint for ancestors of a node.
     */
    public function orWhereAncestorOf(Model|int|string $id, bool $andSelf = false): static
    {
        return $this->whereAncestorOf($id, $andSelf, 'or');
    }

    /**
     * Limit results to ancestors and the node itself.
     */
    public function whereAncestorOrSelf(Model|int|string $id): static
    {
        return $this->whereAncestorOf($id, true);
    }

    /**
     * Get ancestors of specified node.
     */
    public function ancestorsOf(Model|int|string $id, array $columns = ['*']): BaseCollection
    {
        return $this->whereAncestorOf($id)->get($columns);
    }

    /**
     * Get ancestors and the node itself.
     */
    public function ancestorsAndSelf(Model|int|string $id, array $columns = ['*']): BaseCollection
    {
        return $this->whereAncestorOf($id, true)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     */
    public function whereNodeBetween(array $values, string $boolean = 'and', bool $not = false, ?BaseQueryBuilder $query = null): static
    {
        ($query ?? $this->query)->whereBetween(
            $this->model->qualifyColumn($this->model->getLftName()), /* @phpstan-ignore method.notFound */
            $values,
            $boolean,
            $not,
        );

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
    public function whereDescendantOf(Model|int|string $id, string $boolean = 'and', bool $not = false, bool $andSelf = false): static
    {
        $this->query->whereNested(function (BaseQueryBuilder $inner) use ($id, $andSelf, $not) {
            if ($id instanceof Model) {
                $this->ensureUsableNodeForPositionalQuery($id);
                $id->applyNestedSetScope($inner); /* @phpstan-ignore method.notFound */
                $data = $id->getBounds(); /* @phpstan-ignore method.notFound */
            } else {
                $this->ensureConcreteNestedSetScope('scalar lookup');

                $data = $this->newNestedSetLookupQuery()->getPlainNodeData($id, true);
            }

            // Don't include the node
            if (! $andSelf) {
                ++$data[0];
            }

            return $this->whereNodeBetween($data, 'and', $not, $inner);
        }, $boolean);

        return $this;
    }

    /**
     * Exclude descendants of a node.
     */
    public function whereNotDescendantOf(Model|int|string $id): static
    {
        return $this->whereDescendantOf($id, 'and', true);
    }

    /**
     * Add an `or` constraint for descendants of a node.
     */
    public function orWhereDescendantOf(Model|int|string $id): static
    {
        return $this->whereDescendantOf($id, 'or');
    }

    /**
     * Add an `or` exclusion for descendants of a node.
     */
    public function orWhereNotDescendantOf(Model|int|string $id): static
    {
        return $this->whereDescendantOf($id, 'or', true);
    }

    /**
     * Limit results to descendants and the node itself.
     */
    public function whereDescendantOrSelf(Model|int|string $id, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereDescendantOf($id, $boolean, $not, true);
    }

    /**
     * Get descendants of specified node.
     */
    public function descendantsOf(Model|int|string $id, array $columns = ['*'], bool $andSelf = false): BaseCollection
    {
        try {
            return $this->whereDescendantOf($id, 'and', false, $andSelf)->get($columns);
        } catch (ModelNotFoundException $e) {
            return $this->model->newCollection();
        }
    }

    /**
     * Get descendants and the node itself.
     */
    public function descendantsAndSelf(Model|int|string $id, array $columns = ['*']): BaseCollection
    {
        return $this->descendantsOf($id, $columns, true);
    }

    /**
     * Add a positional constraint relative to a node.
     */
    protected function whereIsBeforeOrAfter(Model|int|string $id, string $operator, string $boolean): static
    {
        $model = null;

        if ($id instanceof Model) {
            $model = $id;
            $this->ensureUsableNodeForPositionalQuery($model);
            $value = '?';
            $bindings = [$id->getLft()]; /* @phpstan-ignore method.notFound */
        } else {
            $this->ensureConcreteNestedSetScope('scalar lookup');

            $valueQuery = $this->model
                ->newNestedSetQuery('_n') /* @phpstan-ignore method.notFound */
                ->toBase()
                ->select('_n.' . $this->model->getLftName()) /* @phpstan-ignore method.notFound */
                ->from($this->model->getTable() . ' as _n')
                ->where('_n.' . $this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
            $bindings = [];
        }

        [$lft] = $this->wrappedColumns();

        $this->query->whereNested(function (BaseQueryBuilder $inner) use (
            $model,
            $lft,
            $operator,
            $value,
            $bindings,
        ) {
            if ($model !== null) {
                $model->applyNestedSetScope($inner); /* @phpstan-ignore method.notFound */
            }

            $inner->whereRaw("{$lft} {$operator} {$value}", $bindings);
        }, $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are after specified node.
     */
    public function whereIsAfter(Model|int|string $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '>', $boolean);
    }

    /**
     * Constraint nodes to those that are before specified node.
     */
    public function whereIsBefore(Model|int|string $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '<', $boolean);
    }

    /**
     * Limit results to leaf nodes.
     */
    public function whereIsLeaf(): static
    {
        [$lft, $rgt] = $this->wrappedColumns();

        $this->query->whereRaw("{$lft} = {$rgt} - 1");

        return $this;
    }

    /**
     * Get the leaf nodes.
     */
    public function leaves(array $columns = ['*']): BaseCollection
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

        $grammar = $this->query->getGrammar();
        $column = $grammar->wrap($this->model->qualifyColumn($this->model->getDepthName())); /* @phpstan-ignore method.notFound */
        $alias = $grammar->wrap($as);

        $this->query->selectRaw("{$column} as {$alias}");

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     */
    protected function wrappedColumns(): array
    {
        $grammar = $this->query->getGrammar();

        return [
            $grammar->wrap($this->model->qualifyColumn($this->model->getLftName())), /* @phpstan-ignore method.notFound */
            $grammar->wrap($this->model->qualifyColumn($this->model->getRgtName())), /* @phpstan-ignore method.notFound */
        ];
    }

    /**
     * Exclude root node from the result.
     */
    public function withoutRoot(): static
    {
        $this->query->whereNotNull(
            $this->model->qualifyColumn($this->model->getParentIdName()), /* @phpstan-ignore method.notFound */
        );

        return $this;
    }

    /**
     * Equivalent of `withoutRoot`.
     */
    public function hasParent(): static
    {
        $this->query->whereNotNull(
            $this->model->qualifyColumn($this->model->getParentIdName()), /* @phpstan-ignore method.notFound */
        );

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
        $this->query->reorder();

        $lftName = $this->model->getLftName(); /* @phpstan-ignore method.notFound */

        // A compound query can order only by columns projected in its result.
        $column = $this->query->unions
            ? $lftName
            : $this->model->qualifyColumn($lftName);

        $this->query->orderBy(
            $column,
            $dir,
        );

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
     *
     * @param array<string, int> $nodeData complete values keyed by the model's left, right, and depth column names
     */
    public function moveNode(
        int|string $key,
        int $position,
        ?int $targetDepth = null,
        array $nodeData = [],
    ): int {
        $this->ensureConcreteNestedSetScope('node movement');
        $this->query->useWritePdo();

        $data = $nodeData ?: $this->newNestedSetLookupQuery()->getNodeData($key, true);
        $lftName = $this->model->getLftName(); /* @phpstan-ignore method.notFound */
        $rgtName = $this->model->getRgtName(); /* @phpstan-ignore method.notFound */
        $depthName = $this->model->getDepthName(); /* @phpstan-ignore method.notFound */

        foreach ([$lftName, $rgtName, $depthName] as $column) {
            if (! isset($data[$column])) {
                throw new LogicException(sprintf(
                    'Node data for [%s] must contain [%s], [%s], and [%s].',
                    $this->model::class,
                    $lftName,
                    $rgtName,
                    $depthName,
                ));
            }
        }

        $lft = (int) $data[$lftName];
        $rgt = (int) $data[$rgtName];
        $currentDepth = (int) $data[$depthName];

        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach its destination
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

        $depth = ($targetDepth ?? $this->depthForPosition($position)) - $currentDepth;
        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance', 'depth');

        $boundary = [$from, $to];

        $query = $this->toBase()
            ->where($this->model->getRgtName(), '>=', $boundary[0]) /* @phpstan-ignore method.notFound */
            ->where($this->model->getLftName(), '<=', $boundary[1]); /* @phpstan-ignore method.notFound */

        return $query->update($this->patch($params));
    }

    /**
     * Get the depth of a node inserted at the given position.
     *
     * This replaces upstream's getDepth($position), which returns the enclosing node's depth.
     */
    public function depthForPosition(int $position): int
    {
        $this->ensureConcreteNestedSetScope('depth lookup');

        // A base read keeps this internal lookup from hydrating a model and firing retrieved listeners.
        $depth = $this->newNestedSetLookupQuery()
            ->where($this->model->getLftName(), '<', $position) /* @phpstan-ignore method.notFound */
            ->where($this->model->getRgtName(), '>=', $position) /* @phpstan-ignore method.notFound */
            ->orderBy($this->model->getLftName(), 'desc') /* @phpstan-ignore method.notFound */
            ->toBase()
            ->value($this->model->getDepthName()); /* @phpstan-ignore method.notFound */

        return $depth === null ? 0 : ((int) $depth + 1);
    }

    /**
     * Create a nested set lookup that inherits the current read route.
     */
    protected function newNestedSetLookupQuery(?string $table = null): self
    {
        $query = $this->model->newNestedSetQuery($table); /* @phpstan-ignore method.notFound */

        return $this->query->useWritePdo ? $query->useWritePdo() : $query;
    }

    /**
     * Create a base query for wrapping lookups that inherits the current read route.
     */
    protected function newLookupBaseQuery(): BaseQueryBuilder
    {
        $query = $this->query->newQuery();

        return $this->query->useWritePdo ? $query->useWritePdo() : $query;
    }

    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     */
    public function makeGap(int $cut, int $height): int
    {
        if ($height === 0) {
            return 0;
        }

        $this->ensureConcreteNestedSetScope('gap mutation');

        $params = compact('cut', 'height');

        $query = $this->toBase()
            ->where($this->model->getRgtName(), '>=', $cut); /* @phpstan-ignore method.notFound */

        return $query->update($this->patch($params));
    }

    /**
     * Get patch for columns.
     */
    protected function patch(array $params): array
    {
        $grammar = $this->query->getGrammar();

        $columns = [];

        // MySQL and MariaDB evaluate assignments in order, so depth must read
        // the original left bound before the interval columns are updated.
        if (($params['depth'] ?? 0) !== 0) {
            $column = $this->model->getDepthName(); /* @phpstan-ignore method.notFound */
            $columns[$column] = $this->depthPatch($grammar->wrap($column), $params);
        }

        foreach ([
            $this->model->getLftName(), /* @phpstan-ignore method.notFound */
            $this->model->getRgtName(), /* @phpstan-ignore method.notFound */
        ] as $col) {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get the depth-column patch for a moved subtree.
     */
    protected function depthPatch(string $column, array $params): Expression
    {
        $depth = (int) $params['depth'];
        $lft = (int) $params['lft'];
        $rgt = (int) $params['rgt'];
        $operator = $depth > 0 ? '+' : '-';
        $distance = abs($depth);

        return new Expression(
            "case when {$this->query->getGrammar()->wrap($this->model->getLftName())} " /* @phpstan-ignore method.notFound */
                . "between {$lft} and {$rgt} then {$column} {$operator} {$distance} else {$column} end"
        );
    }

    /**
     * Get patch for single column.
     */
    protected function columnPatch(string $col, array $params): Expression
    {
        $height = (int) $params['height'];

        if ($height >= 0) {
            $height = " + {$height}";
        }

        if (isset($params['cut'])) {
            $cut = (int) $params['cut'];

            return new Expression("case when {$col} >= {$cut} then {$col}{$height} else {$col} end");
        }

        $distance = (int) $params['distance'];
        $lft = (int) $params['lft'];
        $rgt = (int) $params['rgt'];
        $from = (int) $params['from'];
        $to = (int) $params['to'];

        if ($distance >= 0) {
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
        $this->ensureConcreteNestedSetScope();

        $checks = [
            'invalid_intervals' => $this->getInvalidIntervalsQuery(),
            'duplicate_endpoints' => $this->getDuplicateEndpointsQuery(),
            'missing_endpoints' => $this->getMissingEndpointsQuery(),
            'crossing_intervals' => $this->getCrossingIntervalsQuery(),
            'missing_parent' => $this->getMissingParentQuery(),
            'wrong_parent' => $this->getWrongParentQuery(),
            'wrong_depth' => $this->getWrongDepthQuery(),
        ];

        $query = $this->newLookupBaseQuery();

        foreach ($checks as $key => $inner) {
            $query->selectSub($inner, $key);
        }

        return array_map(
            static fn (mixed $value): int => (int) $value,
            (array) $query->first(),
        );
    }

    /**
     * Get the invalid interval query.
     */
    protected function getInvalidIntervalsQuery(bool $count = true): BaseQueryBuilder
    {
        $query = $this->newNestedSetLookupQuery()
            ->toBase()
            ->whereNested(function (BaseQueryBuilder $inner) {
                [$lft, $rgt] = $this->wrappedColumns();

                $inner->whereRaw("{$lft} <= 0")
                    ->orWhereRaw("{$rgt} <= 0")
                    ->orWhereRaw("{$lft} >= {$rgt}")
                    ->orWhereRaw("({$rgt} - {$lft}) % 2 = 0");
            });

        return $count ? $query->selectRaw('count(*)') : $query;
    }

    /**
     * Get the ordered endpoint events query.
     */
    protected function getEndpointEventsQuery(): BaseQueryBuilder
    {
        [$lft, $rgt] = $this->wrappedColumns();
        $depth = $this->query->getGrammar()->wrap($this->model->getDepthName()); /* @phpstan-ignore method.notFound */

        // A left endpoint opens a node at its stored depth, while a right
        // endpoint closes it after one additional active level.
        $lftQuery = $this->newNestedSetLookupQuery()
            ->toBase()
            ->selectRaw("{$lft} as endpoint, {$depth} as expected, 1 as delta");

        $rgtQuery = $this->newNestedSetLookupQuery()
            ->toBase()
            ->selectRaw("{$rgt} as endpoint, {$depth} + 1 as expected, -1 as delta");

        return $lftQuery->unionAll($rgtQuery);
    }

    /**
     * Get duplicate endpoint groups.
     */
    protected function getDuplicateEndpointGroupsQuery(): BaseQueryBuilder
    {
        return $this->newLookupBaseQuery()
            ->fromSub($this->getEndpointEventsQuery(), 'endpoint_events')
            ->select('endpoint')
            ->groupBy('endpoint')
            ->havingRaw('count(*) > 1');
    }

    /**
     * Get the duplicate endpoint count query.
     */
    protected function getDuplicateEndpointsQuery(): BaseQueryBuilder
    {
        return $this->newLookupBaseQuery()
            ->fromSub($this->getDuplicateEndpointGroupsQuery(), 'duplicate_endpoints')
            ->selectRaw('count(*)');
    }

    /**
     * Get the missing endpoint range query.
     */
    protected function getMissingEndpointsQuery(): BaseQueryBuilder
    {
        $statistics = $this->newLookupBaseQuery()
            ->fromSub($this->getEndpointEventsQuery(), 'endpoint_events')
            ->selectRaw(
                'count(*) as endpoint_count, '
                . 'min(endpoint) as minimum_endpoint, '
                . 'max(endpoint) as maximum_endpoint'
            );

        return $this->newLookupBaseQuery()
            ->fromSub($statistics, 'endpoint_statistics')
            ->selectRaw(
                'case '
                . 'when endpoint_count = 0 then 0 '
                . 'when minimum_endpoint = 1 and maximum_endpoint = endpoint_count then 0 '
                . 'else 1 end as missing_endpoints'
            );
    }

    /**
     * Get expected and active endpoint depths.
     */
    protected function getEndpointStateQuery(): BaseQueryBuilder
    {
        // Comparing each event's expected depth with the active count before
        // that endpoint detects intervals which cross instead of nest.
        return $this->newLookupBaseQuery()
            ->fromSub($this->getEndpointEventsQuery(), 'endpoint_events')
            ->select(['expected'])
            ->selectRaw(
                'coalesce(sum(delta) over (order by endpoint '
                . 'rows between unbounded preceding and 1 preceding), 0) as active_before'
            );
    }

    /**
     * Get the crossing interval count query.
     */
    protected function getCrossingIntervalsQuery(): BaseQueryBuilder
    {
        $duplicates = $this->getDuplicateEndpointGroupsQuery()
            ->selectRaw('1')
            ->limit(1);

        return $this->newLookupBaseQuery()
            ->fromSub($this->getEndpointStateQuery(), 'endpoint_state')
            ->selectRaw(
                'case when exists (' . $duplicates->toSql()
                . ') then 0 else count(*) end as crossing_intervals',
                $duplicates->getBindings(),
            )
            ->whereColumn('active_before', '<>', 'expected');
    }

    /**
     * Get the missing parent query.
     */
    protected function getMissingParentQuery(bool $count = true): BaseQueryBuilder
    {
        $childAlias = 'nested_set_child';
        $parentAlias = 'nested_set_parent';
        $parentIdName = $this->model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $keyName = $this->model->getKeyName();

        $query = $this->newNestedSetLookupQuery($childAlias)
            ->toBase()
            ->from($this->model->getTable() . ' as ' . $childAlias)
            ->leftJoin(
                $this->model->getTable() . ' as ' . $parentAlias,
                function (JoinClause $join) use ($childAlias, $parentAlias, $parentIdName, $keyName) {
                    $join->on(
                        $childAlias . '.' . $parentIdName,
                        '=',
                        $parentAlias . '.' . $keyName,
                    );

                    $this->addScopeColumnComparisons($join, $childAlias, $parentAlias);
                }
            )
            ->whereNotNull($childAlias . '.' . $parentIdName)
            ->whereNull($parentAlias . '.' . $keyName);

        return $count ? $query->selectRaw('count(*)') : $query;
    }

    /**
     * Get the wrong parent query.
     */
    protected function getWrongParentQuery(bool $count = true): BaseQueryBuilder
    {
        $childAlias = 'nested_set_child';
        $parentAlias = 'nested_set_parent';
        $parentIdName = $this->model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $keyName = $this->model->getKeyName();
        $lftName = $this->model->getLftName(); /* @phpstan-ignore method.notFound */
        $rgtName = $this->model->getRgtName(); /* @phpstan-ignore method.notFound */
        $depthName = $this->model->getDepthName(); /* @phpstan-ignore method.notFound */
        $grammar = $this->query->getGrammar();

        $query = $this->newNestedSetLookupQuery($childAlias)
            ->toBase()
            ->from($this->model->getTable() . ' as ' . $childAlias)
            ->join(
                $this->model->getTable() . ' as ' . $parentAlias,
                function (JoinClause $join) use ($childAlias, $parentAlias, $parentIdName, $keyName) {
                    $join->on(
                        $childAlias . '.' . $parentIdName,
                        '=',
                        $parentAlias . '.' . $keyName,
                    );

                    $this->addScopeColumnComparisons($join, $childAlias, $parentAlias);
                }
            )
            ->where(function (BaseQueryBuilder $query) use (
                $childAlias,
                $parentAlias,
                $lftName,
                $rgtName,
                $depthName,
                $grammar,
            ) {
                $query->whereColumn(
                    $childAlias . '.' . $lftName,
                    '<=',
                    $parentAlias . '.' . $lftName,
                )->orWhereColumn(
                    $childAlias . '.' . $rgtName,
                    '>=',
                    $parentAlias . '.' . $rgtName,
                )->orWhereRaw(
                    $grammar->wrap($childAlias . '.' . $depthName)
                    . ' <> '
                    . $grammar->wrap($parentAlias . '.' . $depthName)
                    . ' + 1'
                );
            });

        return $count ? $query->selectRaw('count(*)') : $query;
    }

    /**
     * Get the wrong depth query.
     */
    protected function getWrongDepthQuery(bool $count = true): BaseQueryBuilder
    {
        $childAlias = 'nested_set_child';
        $parentAlias = 'nested_set_parent';
        $parentIdName = $this->model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $keyName = $this->model->getKeyName();
        $depthName = $this->model->getDepthName(); /* @phpstan-ignore method.notFound */
        $grammar = $this->query->getGrammar();

        $query = $this->newNestedSetLookupQuery($childAlias)
            ->toBase()
            ->from($this->model->getTable() . ' as ' . $childAlias)
            ->leftJoin(
                $this->model->getTable() . ' as ' . $parentAlias,
                function (JoinClause $join) use ($childAlias, $parentAlias, $parentIdName, $keyName) {
                    $join->on(
                        $childAlias . '.' . $parentIdName,
                        '=',
                        $parentAlias . '.' . $keyName,
                    );

                    $this->addScopeColumnComparisons($join, $childAlias, $parentAlias);
                }
            )
            ->where(function (BaseQueryBuilder $query) use (
                $childAlias,
                $parentAlias,
                $parentIdName,
                $keyName,
                $depthName,
                $grammar,
            ) {
                $query->where(function (BaseQueryBuilder $query) use ($childAlias, $parentIdName, $depthName) {
                    $query->whereNull($childAlias . '.' . $parentIdName)
                        ->where($childAlias . '.' . $depthName, '<>', 0);
                })->orWhere(function (BaseQueryBuilder $query) use (
                    $childAlias,
                    $parentAlias,
                    $parentIdName,
                    $keyName,
                    $depthName,
                    $grammar,
                ) {
                    $query->whereNotNull($childAlias . '.' . $parentIdName)
                        ->whereNotNull($parentAlias . '.' . $keyName)
                        ->whereRaw(
                            $grammar->wrap($childAlias . '.' . $depthName)
                            . ' <> '
                            . $grammar->wrap($parentAlias . '.' . $depthName)
                            . ' + 1'
                        );
                });
            });

        return $count ? $query->selectRaw('count(*)') : $query;
    }

    /**
     * Add null-safe scope comparisons between aliases.
     */
    protected function addScopeColumnComparisons(
        BaseQueryBuilder $query,
        string $firstAlias,
        string $secondAlias,
    ): void {
        foreach (array_keys($this->model->getNestedSetScope()) as $attribute) { /* @phpstan-ignore method.notFound */
            $first = $firstAlias . '.' . $attribute;
            $second = $secondAlias . '.' . $attribute;

            $query->where(function (BaseQueryBuilder $query) use ($first, $second) {
                $query->whereColumn($first, '=', $second)
                    ->orWhere(function (BaseQueryBuilder $query) use ($first, $second) {
                        $query->whereNull($first)
                            ->whereNull($second);
                    });
            });
        }
    }

    /**
     * Ensure every nested set scope attribute is selected.
     */
    protected function ensureConcreteNestedSetScope(string $operation = 'diagnostics'): void
    {
        $attributes = $this->model->getAttributes();

        foreach (array_keys($this->model->getNestedSetScope()) as $attribute) { /* @phpstan-ignore method.notFound */
            if (! array_key_exists($attribute, $attributes)) {
                throw new LogicException(sprintf(
                    'Nested set %s for [%s] requires a concrete scoped([...]) selection because attribute [%s] was not selected.',
                    $operation,
                    $this->model::class,
                    $attribute,
                ));
            }
        }
    }

    /**
     * Ensure that a node can supply coordinates to this query.
     */
    protected function ensureUsableNodeForPositionalQuery(Model $node): void
    {
        if (! NestedSet::isNode($node)) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must be node.',
                $node::class,
            ));
        }

        if (NestedSet::structuralIdentity($this->model) !== NestedSet::structuralIdentity($node)) {
            throw new LogicException(sprintf(
                'Nested set node [%s] uses %s, but query model [%s] uses %s.',
                $node::class,
                NestedSet::structuralDescription($node),
                $this->model::class,
                NestedSet::structuralDescription($this->model),
            ));
        }

        if ($node->getLft() === null /* @phpstan-ignore method.notFound */
            || $node->getRgt() === null /* @phpstan-ignore method.notFound */
        ) {
            throw new LogicException(sprintf(
                'Nested set node [%s] must have loaded bounds.',
                $node::class,
            ));
        }

        $attributes = $node->getAttributes();

        foreach (array_keys($node->getNestedSetScope()) as $attribute) { /* @phpstan-ignore method.notFound */
            if (! array_key_exists($attribute, $attributes)) {
                throw new LogicException(sprintf(
                    'Nested set node [%s] must have scope attribute [%s] selected.',
                    $node::class,
                    $attribute,
                ));
            }
        }
    }

    /**
     * Prepare one authoritative root for subtree repair or rebuild.
     */
    protected function prepareRepairRoot(Model $root, string $operation): void
    {
        if (! NestedSet::isNode($root)) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must be node.',
                $root::class,
            ));
        }

        if (! $root->exists) {
            throw new LogicException(sprintf(
                'Nested set %s root [%s] with key [%s] must be persisted.',
                $operation,
                $root::class,
                $root->getKey() ?? 'null',
            ));
        }

        if (NestedSet::structuralIdentity($this->model) !== NestedSet::structuralIdentity($root)) {
            throw new LogicException(sprintf(
                'Nested set %s root [%s] uses %s, but query model [%s] uses %s.',
                $operation,
                $root::class,
                NestedSet::structuralDescription($root),
                $this->model::class,
                NestedSet::structuralDescription($this->model),
            ));
        }

        $root->prepareForNestedSetMutation(); /* @phpstan-ignore method.notFound */

        if ($this->model->hasCompleteNestedSetScope() /* @phpstan-ignore method.notFound */
            && $this->model->getNestedSetScopeKey() /* @phpstan-ignore method.notFound */
                !== $root->getNestedSetScopeKey() /* @phpstan-ignore method.notFound */
        ) {
            throw new LogicException(sprintf(
                'Nested set %s root [%s] does not match the query scoped([...]) selection.',
                $operation,
                $root::class,
            ));
        }

        $this->ensureSubtreeBoundsAreValid($root);
    }

    /**
     * Ensure a subtree has valid bounds containing every parent-linked node.
     */
    protected function ensureSubtreeBoundsAreValid(Model $root): void
    {
        $keyName = $root->getKeyName();
        $parentIdName = $root->getParentIdName(); /* @phpstan-ignore method.notFound */
        $lftName = $root->getLftName(); /* @phpstan-ignore method.notFound */
        $lft = $root->getLft(); /* @phpstan-ignore method.notFound */
        $rgt = $root->getRgt(); /* @phpstan-ignore method.notFound */

        if ($lft === null || $rgt === null || $lft < 1 || $rgt <= $lft) {
            throw new LogicException(sprintf(
                'Nested set subtree for [%s] with key [%s] has invalid stored bounds.',
                $root::class,
                $root->getKey() ?? 'null',
            ));
        }

        $bounds = [$lft, $rgt];

        $selectedKeys = $root
            ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
            ->select($keyName)
            ->whereBetween($lftName, $bounds);

        $crossesBoundary = $root
            ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
            ->useWritePdo()
            ->whereIn($parentIdName, $selectedKeys)
            ->whereNotBetween($lftName, $bounds)
            ->exists();

        if ($crossesBoundary) {
            throw new LogicException(sprintf(
                'Nested set subtree for [%s] with key [%s] has parentage that crosses its stored bounds.',
                $root::class,
                $root->getKey() ?? 'null',
            ));
        }
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
        $this->ensureConcreteNestedSetScope();

        if ($this->getInvalidIntervalsQuery(false)->exists()
            || $this->getDuplicateEndpointGroupsQuery()->exists()
            || (int) $this->getMissingEndpointsQuery()->value('missing_endpoints') > 0
            || $this->getMissingParentQuery(false)->exists()
            || $this->getWrongParentQuery(false)->exists()
            || $this->getWrongDepthQuery(false)->exists()
        ) {
            return true;
        }

        return (int) $this->getCrossingIntervalsQuery()->value('crossing_intervals') > 0;
    }

    /**
     * Fix the tree based on parentage information.
     *
     * Nodes with invalid parents become roots of the repaired selection.
     */
    public function fixTree(?Model $root = null, array $extraColumns = []): int
    {
        if ($root === null) {
            $this->ensureConcreteNestedSetScope('repair');
        } else {
            $this->prepareRepairRoot($root, 'subtree repair');
        }

        $model = $root ?? $this->model;
        $parentIdName = $model->getParentIdName(); /* @phpstan-ignore method.notFound */
        $scopeColumns = array_keys($model->getNestedSetScope()); /* @phpstan-ignore method.notFound */
        $columns = array_values(array_unique([
            $model->getKeyName(),
            $parentIdName,
            $model->getLftName(), /* @phpstan-ignore method.notFound */
            $model->getRgtName(), /* @phpstan-ignore method.notFound */
            $model->getDepthName(), /* @phpstan-ignore method.notFound */
            ...$scopeColumns,
            ...$extraColumns,
        ]));

        // Repair reads plain rows and hydrates only the nodes it saves, so a
        // large, mostly healthy tree is not loaded as models.
        $rows = $model
            ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
            ->useWritePdo()
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->defaultOrder()
            ->toBase()
            ->get($columns);

        $roots = [];
        $childrenByParent = [];
        $parentOrder = [];

        foreach ($rows as $row) {
            static::addRepairNode($roots, $childrenByParent, $parentOrder, $row, $row->{$parentIdName});
        }

        return $this->fixNodes($roots, $childrenByParent, $parentOrder, $root);
    }

    /**
     * Fix a subtree based on parentage information.
     */
    public function fixSubtree(?Model $root, array $extraColumns = []): int
    {
        return $this->fixTree($root, $extraColumns);
    }

    /**
     * Repair ordered nodes from their parent dictionaries.
     */
    protected function fixNodes(
        array $roots,
        array &$childrenByParent,
        array $parentOrder,
        ?Model $parent = null,
    ): int {
        $model = $parent ?? $this->model;
        $parentId = $parent?->getKey();
        $cut = $parent ? $parent->getLft() + 1 : 1; /* @phpstan-ignore method.notFound */
        $depth = $parent ? $parent->getDepth() + 1 : 0; /* @phpstan-ignore method.notFound */
        $placements = [];
        $moved = 0;

        if ($parent === null) {
            $nodes = $roots;
            $roots = [];
        } else {
            $nodes = $childrenByParent[$parentId] ?? [];
            unset($childrenByParent[$parentId]);
        }

        $cut = static::reorderNodes(
            $childrenByParent,
            $placements,
            $nodes,
            $model->getKeyName(),
            $parentId,
            $cut,
            $depth,
        );

        foreach ($parentOrder as $unresolvedParentId) {
            if ($unresolvedParentId === null) {
                if ($roots === []) {
                    continue;
                }

                $nodes = $roots;
                $roots = [];
            } else {
                if (! array_key_exists($unresolvedParentId, $childrenByParent)) {
                    continue;
                }

                $nodes = $childrenByParent[$unresolvedParentId];
                unset($childrenByParent[$unresolvedParentId]);
            }

            $cut = static::reorderNodes(
                $childrenByParent,
                $placements,
                $nodes,
                $model->getKeyName(),
                $parentId,
                $cut,
                $depth,
            );
        }

        $grown = $parent ? $cut - $parent->getRgt() : 0; /* @phpstan-ignore method.notFound */
        $gapCut = null;

        if ($parent !== null && $grown !== 0) {
            $gapCut = $parent->getRgt() + 1; /* @phpstan-ignore method.notFound */
            $moved = $parent
                ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
                ->makeGap($gapCut, $grown);
        }

        $changed = 0;

        foreach ($placements as $placement) {
            if ($this->applyRepairPlacement($model, $placement, $gapCut, $grown)) {
                ++$changed;
            }
        }

        if ($parent !== null && $gapCut !== null) {
            static::saveRepairNode($parent->rawNode( /* @phpstan-ignore method.notFound */
                $parent->getLft(), /* @phpstan-ignore method.notFound */
                $cut,
                $parent->getParentId(), /* @phpstan-ignore method.notFound */
                $parent->getDepth(), /* @phpstan-ignore method.notFound */
            ));

            ++$changed;
        }

        return $changed + $moved;
    }

    /**
     * Apply a repair placement and save the node when its stored values differ.
     *
     * Returns whether the placement differs from the values stored before any subtree gap,
     * so rows that only the gap update shifted are counted by that update instead.
     *
     * @param array{0: Model|stdClass, 1: int, 2: int, 3: null|int|string, 4: int} $placement
     */
    protected function applyRepairPlacement(Model $model, array $placement, ?int $gapCut, int $height): bool
    {
        [$node, $lft, $rgt, $parentId, $depth] = $placement;
        $lftName = $model->getLftName(); /* @phpstan-ignore method.notFound */
        $rgtName = $model->getRgtName(); /* @phpstan-ignore method.notFound */

        if ($node instanceof Model) {
            static::assignRepairNode($node, $lft, $rgt, $parentId, $depth);

            $changed = $node->isDirty();

            if ($gapCut !== null) {
                $attributes = $node->getAttributes();

                $node->setRawAttributes(
                    static::shiftRepairBoundsAfterGap($node->getRawOriginal(), $lftName, $rgtName, $gapCut, $height),
                    true,
                );
                $node->setRawAttributes($attributes);
            }

            if ($node->isDirty()) {
                static::saveRepairNode($node);
            }

            return $changed;
        }

        $changed = ! $this->repairRowMatches($model, $node, $placement);

        if ($gapCut !== null) {
            $node = (object) static::shiftRepairBoundsAfterGap((array) $node, $lftName, $rgtName, $gapCut, $height);
        }

        if (! $this->repairRowMatches($model, $node, $placement)) {
            $repaired = $model->newFromBuilder($node);

            static::assignRepairNode($repaired, $lft, $rgt, $parentId, $depth);
            static::saveRepairNode($repaired);
        }

        return $changed;
    }

    /**
     * Determine whether a row already stores a repair placement.
     *
     * @param array{0: Model|stdClass, 1: int, 2: int, 3: null|int|string, 4: int} $placement
     */
    protected function repairRowMatches(Model $model, stdClass $row, array $placement): bool
    {
        $stored = [
            1 => $row->{$model->getLftName()}, /* @phpstan-ignore method.notFound */
            2 => $row->{$model->getRgtName()}, /* @phpstan-ignore method.notFound */
            3 => $row->{$model->getParentIdName()}, /* @phpstan-ignore method.notFound */
            4 => $row->{$model->getDepthName()}, /* @phpstan-ignore method.notFound */
        ];

        foreach ($stored as $index => $value) {
            $target = $placement[$index];

            // Drivers return numbers as integers or numeric strings; null stays distinct from zero.
            if ($value === null || $target === null ? $value !== $target : (string) $value !== (string) $target) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shift stored bounds as the preceding gap update shifted the row.
     */
    protected static function shiftRepairBoundsAfterGap(
        array $attributes,
        string $lftName,
        string $rgtName,
        int $cut,
        int $height,
    ): array {
        $lft = $attributes[$lftName];
        $rgt = $attributes[$rgtName];

        if ($rgt === null || (int) $rgt < $cut) {
            return $attributes;
        }

        // Mirror makeGap() and columnPatch(), so dirtiness is computed against
        // the values that update left in the row.
        $attributes[$lftName] = $lft !== null && (int) $lft >= $cut ? (int) $lft + $height : $lft;
        $attributes[$rgtName] = (int) $rgt + $height;

        return $attributes;
    }

    /**
     * Place a set of nodes at contiguous bounds and depths, recording each placement in tree order.
     */
    protected static function reorderNodes(
        array &$childrenByParent,
        array &$placements,
        array $nodes,
        string $keyName,
        int|string|null $parentId,
        int $cut,
        int $depth,
    ): int {
        $stack = [[
            'nodes' => array_values($nodes),
            'index' => 0,
            'parent_id' => $parentId,
            'depth' => $depth,
        ]];

        while ($stack !== []) {
            $frameIndex = array_key_last($stack);

            if (isset($stack[$frameIndex]['node'])) {
                $frame = array_pop($stack);

                $placements[] = [$frame['node'], $frame['lft'], $cut, $frame['parent_id'], $frame['depth']];

                ++$cut;

                continue;
            }

            if ($stack[$frameIndex]['index'] >= count($stack[$frameIndex]['nodes'])) {
                array_pop($stack);

                continue;
            }

            $node = $stack[$frameIndex]['nodes'][$stack[$frameIndex]['index']];
            ++$stack[$frameIndex]['index'];

            $stack[] = [
                'node' => $node,
                'lft' => $cut++,
                'parent_id' => $stack[$frameIndex]['parent_id'],
                'depth' => $stack[$frameIndex]['depth'],
            ];

            $key = $node->{$keyName};

            if ($key === null || ! array_key_exists($key, $childrenByParent)) {
                continue;
            }

            $children = $childrenByParent[$key];
            unset($childrenByParent[$key]);

            $stack[] = [
                'nodes' => array_values($children),
                'index' => 0,
                'parent_id' => $key,
                'depth' => $stack[$frameIndex]['depth'] + 1,
            ];
        }

        return $cut;
    }

    /**
     * Assign structural values to a model owned by repair or rebuild.
     */
    protected static function assignRepairNode(
        Model $model,
        int $lft,
        int $rgt,
        int|string|null $parentId,
        ?int $depth,
    ): void {
        $model->setLft($lft); /* @phpstan-ignore method.notFound */
        $model->setRgt($rgt); /* @phpstan-ignore method.notFound */
        $model->setParentId($parentId); /* @phpstan-ignore method.notFound */
        $model->setDepth($depth); /* @phpstan-ignore method.notFound */
    }

    /**
     * Add a repair node to its parent bucket.
     */
    protected static function addRepairNode(
        array &$roots,
        array &$childrenByParent,
        array &$parentOrder,
        Model|stdClass $node,
        int|string|null $parentId,
    ): void {
        if ($parentId === null) {
            if ($roots === []) {
                $parentOrder[] = null;
            }

            $roots[] = $node;

            return;
        }

        if (! array_key_exists($parentId, $childrenByParent)) {
            $parentOrder[] = $parentId;
        }

        $childrenByParent[$parentId][] = $node;
    }

    /**
     * Save a node changed during repair.
     */
    protected static function saveRepairNode(Model $model): void
    {
        if ($model->save()) {
            return;
        }

        throw new LogicException(sprintf(
            'Saving nested set node [%s] with key [%s] during repair was vetoed.',
            $model::class,
            $model->getKey() ?? 'null',
        ));
    }

    /**
     * Rebuild the tree based on raw data.
     * If item data does not contain primary key, new node will be created.
     *
     * @param bool $delete whether to delete nodes that exists but not in the data array
     */
    public function rebuildTree(array $data, bool $delete = false, ?Model $root = null): int
    {
        if ($root === null) {
            $this->ensureConcreteNestedSetScope('rebuild');
        } else {
            $this->prepareRepairRoot($root, 'subtree rebuild');
        }

        $model = $root ?? $this->model;
        $result = $model
            ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
            ->useWritePdo()
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->get();

        $existing = $result->getDictionary();
        $roots = [];
        $childrenByParent = [];
        $parentOrder = [];
        $parentId = $root?->getKey();
        $scopeAttributes = array_intersect_key(
            $model->getAttributes(),
            array_flip(array_keys($model->getNestedSetScope())), /* @phpstan-ignore method.notFound */
        );

        $this->buildRebuildDictionary(
            $roots,
            $childrenByParent,
            $parentOrder,
            $data,
            $existing,
            $scopeAttributes,
            $parentId,
        );

        if ($existing !== []) {
            $usesSoftDeletes = $model::isSoftDeletable();

            if ($delete && ! $usesSoftDeletes) {
                // MySQL and MariaDB check a restricting parent key as each row is deleted.
                $model
                    ->newNestedSetQuery() /* @phpstan-ignore method.notFound */
                    ->whereIn($model->getKeyName(), array_keys($existing))
                    ->orderBy($model->getLftName(), 'desc') /* @phpstan-ignore method.notFound */
                    ->delete();
            } else {
                $deletedAtColumn = $delete && $usesSoftDeletes
                    ? $model->getDeletedAtColumn()
                    : null;
                $deletedAt = $deletedAtColumn === null
                    ? null
                    : $model->fromDateTime($model->freshTimestamp());

                foreach ($existing as $existingModel) {
                    if ($deletedAtColumn !== null
                        && $existingModel->{$deletedAtColumn} === null
                    ) {
                        $existingModel->{$deletedAtColumn} = $deletedAt;
                    }

                    static::addRepairNode(
                        $roots,
                        $childrenByParent,
                        $parentOrder,
                        $existingModel,
                        $existingModel->getParentId(), /* @phpstan-ignore method.notFound */
                    );
                }
            }
        }

        return $this->fixNodes(
            $roots,
            $childrenByParent,
            $parentOrder,
            $root,
        );
    }

    /**
     * Rebuild a subtree from raw data.
     */
    public function rebuildSubtree(Model $root, array $data, bool $delete = false): int
    {
        return $this->rebuildTree($data, $delete, $root);
    }

    /**
     * Build repair dictionaries from nested rebuild data.
     */
    protected function buildRebuildDictionary(
        array &$roots,
        array &$childrenByParent,
        array &$parentOrder,
        array $data,
        array &$existing,
        array $scopeAttributes,
        int|string|null $parentId = null,
    ): void {
        $keyName = $this->model->getKeyName();

        foreach ($data as $itemData) {
            $children = $itemData['children'] ?? null;

            if (! isset($itemData[$keyName])) {
                $model = $this->model->newInstance();

                // Rebuild owns the tree scope, so a guarded scope attribute still applies.
                $model->setRawAttributes(array_replace($model->getAttributes(), $scopeAttributes));

                // Set temporary values without scheduling a tree action.
                $model->rawNode(0, 0, $parentId, 0); /* @phpstan-ignore method.notFound */
            } else {
                $key = $itemData[$keyName];

                if (! isset($existing[$key])) {
                    throw (new ModelNotFoundException)->setModel($this->model::class, [$key]);
                }

                $model = $existing[$key];

                // Set the intended parent without scheduling a tree action.
                static::assignRepairNode(
                    $model,
                    $model->getLft(),
                    $model->getRgt(),
                    $parentId,
                    $model->getDepth(),
                );

                unset($existing[$key]);
            }

            foreach ([
                'children',
                $keyName,
                $model->getParentIdName(),
                $model->getLftName(),
                $model->getRgtName(),
                $model->getDepthName(),
                ...array_keys($model->getNestedSetScope()),
            ] as $ownedAttribute) {
                unset($itemData[$ownedAttribute]);
            }

            $model->fill($itemData);

            static::saveRepairNode($model);
            static::addRepairNode($roots, $childrenByParent, $parentOrder, $model, $model->getParentId()); /* @phpstan-ignore method.notFound */

            if ($children === null) {
                continue;
            }

            $this->buildRebuildDictionary(
                $roots,
                $childrenByParent,
                $parentOrder,
                $children,
                $existing,
                $scopeAttributes,
                $model->getKey(),
            );
        }
    }

    /**
     * Apply the concrete tree scope.
     */
    public function applyNestedSetScope(?string $table = null): static
    {
        /* @phpstan-ignore method.notFound */
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
