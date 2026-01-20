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
        return '(' . substr($this->compileHavings($having['query']), 7) . ')';
    }
}
