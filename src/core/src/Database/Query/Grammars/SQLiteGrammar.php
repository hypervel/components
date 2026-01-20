<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;
use Hyperf\Database\SQLite\Query\Grammars\SQLiteGrammar as BaseSQLiteGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class SQLiteGrammar extends BaseSQLiteGrammar
{
    use CompilesJsonPaths;
    use CommonGrammar {
        compileGroupLimit as compileWindowGroupLimit;
    }

    /**
     * Compile a select query into SQL.
     *
     * Overrides to add support for groupLimit.
     */
    public function compileSelect(Builder $query): string
    {
        /** @var \Hypervel\Database\Query\Builder $query */
        if (isset($query->groupLimit)) {
            return $this->compileGroupLimit($query);
        }

        return parent::compileSelect($query);
    }

    /**
     * Compile a group limit clause.
     *
     * SQLite < 3.25.0 doesn't support window functions, so we fall back
     * to a regular select query (ignoring groupLimit) on older versions.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        /** @var \Hypervel\Database\Query\Builder $query */
        /** @var \Hypervel\Database\Connections\SQLiteConnection $connection */
        $connection = $query->getConnection();
        $version = $connection->getServerVersion();

        if (version_compare($version, '3.25.0', '>=')) {
            return $this->compileWindowGroupLimit($query);
        }

        $query->groupLimit = null;

        return $this->compileSelect($query);
    }

    /**
     * Compile a "where like" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereLike(Builder $query, array $where): string
    {
        if ($where['caseSensitive'] === false) {
            $where['operator'] = $where['not'] ? 'not like' : 'like';

            return $this->whereBasic($query, $where);
        }

        $where['operator'] = $where['not'] ? 'not glob' : 'glob';

        return $this->whereBasic($query, $where);
    }

    /**
     * Convert a LIKE pattern to a GLOB pattern.
     */
    public function prepareWhereLikeBinding(string $value, bool $caseSensitive): string
    {
        if ($caseSensitive === false) {
            return $value;
        }

        return str_replace(
            ['*', '?', '%', '_'],
            ['[*]', '[?]', '*', '?'],
            $value
        );
    }

    /**
     * Compile a "JSON contains key" statement into SQL.
     */
    protected function compileJsonContainsKey(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'json_type(' . $field . $path . ') is not null';
    }
}
