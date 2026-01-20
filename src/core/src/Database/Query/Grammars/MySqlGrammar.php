<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class MySqlGrammar extends BaseMySqlGrammar
{
    use CompilesJsonPaths;

    /**
     * Compile a "where like" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereLike(Builder $query, array $where): string
    {
        $where['operator'] = $where['not'] ? 'not ' : '';
        $where['operator'] .= $where['caseSensitive'] ? 'like binary' : 'like';

        return $this->whereBasic($query, $where);
    }

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
     * Compile a "JSON contains key" statement into SQL.
     */
    protected function compileJsonContainsKey(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'ifnull(json_contains_path(' . $field . ", 'one'" . $path . '), 0)';
    }
}
