<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\JoinLateralClause;
use Override;
use RuntimeException;

class MariaDbGrammar extends MySqlGrammar
{
    /**
     * Compile the query timeout for a complete select statement.
     */
    #[Override]
    protected function compileSelectTimeout(Builder $query, string $sql): string
    {
        return $query->timeout === null
            ? $sql
            : 'SET STATEMENT max_statement_time=' . $query->timeout . ' FOR ' . $sql;
    }

    /**
     * Compile a "lateral join" clause.
     */
    public function compileJoinLateral(JoinLateralClause $join, string $expression): string
    {
        throw new RuntimeException('This database engine does not support lateral joins.');
    }

    /**
     * Compile a "JSON value cast" statement into SQL.
     */
    public function compileJsonValueCast(string $value): string
    {
        return "json_query({$value}, '$')";
    }

    /**
     * Compile a query to get the number of open connections for a database.
     */
    public function compileThreadCount(): string
    {
        return 'select variable_value as `Value` from information_schema.global_status where variable_name = \'THREADS_CONNECTED\'';
    }

    /**
     * Compile a vector distance expression for the given column.
     */
    public function compileVectorDistanceExpression(Expression|string $column): string
    {
        return "vec_distance_cosine({$this->wrap($column)}, vec_fromtext(?))";
    }

    /**
     * Determine if the grammar supports vector distance queries.
     */
    public function supportsVectorDistance(): bool
    {
        return true;
    }

    /**
     * Determine whether to use a legacy group limit clause for MySQL < 8.0.
     */
    public function useLegacyGroupLimit(Builder $query): bool
    {
        return false;
    }

    /**
     * Wrap the given JSON selector.
     */
    protected function wrapJsonSelector(string $value): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($value);

        return 'json_value(' . $field . $path . ')';
    }
}
