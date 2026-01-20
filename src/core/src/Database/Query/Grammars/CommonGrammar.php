<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;

/**
 * Common grammar methods shared across all database-specific grammars.
 */
trait CommonGrammar
{
    /**
     * Compile a "where between columns" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereBetweenColumns(Builder $query, array $where): string
    {
        $between = $where['not'] ? 'not between' : 'between';

        $min = $this->wrap(reset($where['values']));
        $max = $this->wrap(end($where['values']));

        return $this->wrap($where['column']) . ' ' . $between . ' ' . $min . ' and ' . $max;
    }

    /**
     * Compile a "where JSON contains key" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereJsonContainsKey(Builder $query, array $where): string
    {
        $not = $where['not'] ? 'not ' : '';

        return $not . $this->compileJsonContainsKey($where['column']);
    }

    /**
     * Compile a single having clause.
     *
     * Extends Hyperf's implementation to add support for 'Null', 'NotNull', and 'Nested' types.
     *
     * @param array<string, mixed> $having
     */
    protected function compileHaving(array $having): string
    {
        return match ($having['type']) {
            'Null' => $this->compileHavingNull($having),
            'NotNull' => $this->compileHavingNotNull($having),
            'Nested' => $this->compileNestedHavings($having),
            default => parent::compileHaving($having),
        };
    }

    /**
     * Compile a "having null" clause.
     *
     * @param array<string, mixed> $having
     */
    protected function compileHavingNull(array $having): string
    {
        return $having['boolean'] . ' ' . $this->wrap($having['column']) . ' is null';
    }

    /**
     * Compile a "having not null" clause.
     *
     * @param array<string, mixed> $having
     */
    protected function compileHavingNotNull(array $having): string
    {
        return $having['boolean'] . ' ' . $this->wrap($having['column']) . ' is not null';
    }

    /**
     * Compile a nested having clause.
     *
     * @param array<string, mixed> $having
     */
    protected function compileNestedHavings(array $having): string
    {
        return '(' . substr($this->compileHavings($having['query'], $having['query']->havings), 7) . ')';
    }

    /**
     * Compile a group limit clause using window functions.
     *
     * Wraps the query in a subquery that adds a ROW_NUMBER() column partitioned
     * by the group column, then filters to only rows within the limit.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        /** @var \Hypervel\Database\Query\Builder $query */
        $selectBindings = array_merge($query->getRawBindings()['select'], $query->getRawBindings()['order']);

        $query->setBindings($selectBindings, 'select');
        $query->setBindings([], 'order');

        $limit = (int) $query->groupLimit['value'];

        /** @var int|null $offset */
        $offset = $query->offset;

        if ($offset !== null) {
            $offset = (int) $offset;
            $limit += $offset;

            $query->offset = null; // @phpstan-ignore assign.propertyType
        }

        $components = $this->compileComponents($query);

        $components['columns'] .= $this->compileRowNumber(
            $query->groupLimit['column'],
            $components['orders'] ?? ''
        );

        unset($components['orders']);

        $table = $this->wrap('laravel_table');
        $row = $this->wrap('laravel_row');

        $sql = $this->concatenate($components);

        $sql = 'select * from (' . $sql . ') as ' . $table . ' where ' . $row . ' <= ' . $limit;

        if ($offset !== null) {
            $sql .= ' and ' . $row . ' > ' . $offset;
        }

        return $sql . ' order by ' . $row;
    }

    /**
     * Compile a row number clause for group limit.
     */
    protected function compileRowNumber(string $partition, string $orders): string
    {
        $over = trim('partition by ' . $this->wrap($partition) . ' ' . $orders);

        return ', row_number() over (' . $over . ') as ' . $this->wrap('laravel_row');
    }
}
