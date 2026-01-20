<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;
use Hypervel\Support\Arr;

class MySqlGrammar extends BaseMySqlGrammar
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
     * Uses legacy variable-based implementation for MySQL < 8.0 (which lacks
     * window function support), otherwise uses standard ROW_NUMBER() approach.
     */
    protected function compileGroupLimit(Builder $query): string
    {
        return $this->useLegacyGroupLimit($query)
            ? $this->compileLegacyGroupLimit($query)
            : $this->compileWindowGroupLimit($query);
    }

    /**
     * Determine whether to use a legacy group limit clause for MySQL < 8.0.
     */
    public function useLegacyGroupLimit(Builder $query): bool
    {
        /** @var \Hypervel\Database\Connections\MySqlConnection $connection */
        $connection = $query->getConnection();
        $version = $connection->getServerVersion();

        return ! $connection->isMaria() && version_compare($version, '8.0.11', '<');
    }

    /**
     * Compile a group limit clause for MySQL < 8.0.
     *
     * Uses user variables instead of window functions since MySQL < 8.0 doesn't
     * support ROW_NUMBER().
     */
    protected function compileLegacyGroupLimit(Builder $query): string
    {
        /** @var \Hypervel\Database\Query\Builder $query */
        $limit = (int) $query->groupLimit['value'];

        /** @var int|null $offset */
        $offset = $query->offset;

        if ($offset !== null) {
            $offset = (int) $offset;
            $limit += $offset;

            $query->offset = null; // @phpstan-ignore assign.propertyType
        }

        $column = Arr::last(explode('.', $query->groupLimit['column']));
        $column = $this->wrap($column);

        $partition = ', @laravel_row := if(@laravel_group = ' . $column . ', @laravel_row + 1, 1) as `laravel_row`';
        $partition .= ', @laravel_group := ' . $column;

        $orders = (array) $query->orders;

        array_unshift($orders, [
            'column' => $query->groupLimit['column'],
            'direction' => 'asc',
        ]);

        $query->orders = $orders;

        $components = $this->compileComponents($query);

        $sql = $this->concatenate($components);

        $from = '(select @laravel_row := 0, @laravel_group := 0) as `laravel_vars`, (' . $sql . ') as `laravel_table`';

        $sql = 'select `laravel_table`.*' . $partition . ' from ' . $from . ' having `laravel_row` <= ' . $limit;

        if ($offset !== null) {
            $sql .= ' and `laravel_row` > ' . $offset;
        }

        return $sql . ' order by `laravel_row`';
    }

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
     * Compile a "JSON contains key" statement into SQL.
     */
    protected function compileJsonContainsKey(string $column): string
    {
        [$field, $path] = $this->wrapJsonFieldAndPath($column);

        return 'ifnull(json_contains_path(' . $field . ", 'one'" . $path . '), 0)';
    }
}
