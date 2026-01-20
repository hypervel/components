<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\PgSQL\Query\Grammars\PostgresGrammar as BasePostgresGrammar;
use Hyperf\Database\Query\Builder;
use Hyperf\Stringable\Str;
use Hypervel\Support\Collection;

class PostgresGrammar extends BasePostgresGrammar
{
    /**
     * Compile a "where like" clause.
     *
     * @param array<string, mixed> $where
     */
    protected function whereLike(Builder $query, array $where): string
    {
        $where['operator'] = $where['not'] ? 'not ' : '';
        $where['operator'] .= $where['caseSensitive'] ? 'like' : 'ilike';

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
        $segments = explode('->', $column);

        $lastSegment = array_pop($segments);

        if (filter_var($lastSegment, FILTER_VALIDATE_INT) !== false) {
            $i = (int) $lastSegment;
        } elseif (preg_match('/\[(-?[0-9]+)\]$/', $lastSegment, $matches)) {
            $segments[] = Str::beforeLast($lastSegment, $matches[0]);

            $i = (int) $matches[1];
        }

        $column = str_replace('->>', '->', $this->wrap(implode('->', $segments)));

        if (isset($i)) {
            return vsprintf('case when %s then %s else false end', [
                'jsonb_typeof((' . $column . ")::jsonb) = 'array'",
                'jsonb_array_length((' . $column . ')::jsonb) >= ' . ($i < 0 ? abs($i) : $i + 1),
            ]);
        }

        $key = "'" . str_replace("'", "''", $lastSegment) . "'";

        return 'coalesce((' . $column . ')::jsonb ?? ' . $key . ', false)';
    }

    /**
     * Wrap the attributes of the given JSON path.
     *
     * @param array<int, string> $path
     * @return array<int, int|string>
     */
    protected function wrapJsonPathAttributes($path): array
    {
        /** @var Collection<int, string> $flattened */
        $flattened = (new Collection($path))
            ->map(fn (string $attribute) => $this->parseJsonPathArrayKeys($attribute))
            ->collapse();

        return $flattened
            ->map(function (string $attribute): int|string {
                return filter_var($attribute, FILTER_VALIDATE_INT) !== false
                    ? (int) $attribute
                    : "'{$attribute}'";
            })
            ->all();
    }

    /**
     * Parse the given JSON path attribute for array keys.
     *
     * @return array<int, string>
     */
    protected function parseJsonPathArrayKeys(string $attribute): array
    {
        if (preg_match('/(\[[^\]]+\])+$/', $attribute, $parts)) {
            $key = Str::beforeLast($attribute, $parts[0]);

            preg_match_all('/\[([^\]]+)\]/', $parts[0], $keys);

            return (new Collection([$key]))
                ->merge($keys[1])
                ->filter(fn ($v) => $v !== '')
                ->values()
                ->all();
        }

        return [$attribute];
    }
}
