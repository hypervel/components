<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;
use Hyperf\Database\SQLite\Query\Grammars\SQLiteGrammar as BaseSQLiteGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class SQLiteGrammar extends BaseSQLiteGrammar
{
    use CompilesJsonPaths;
    use CommonGrammar;

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
