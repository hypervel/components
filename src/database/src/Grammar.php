<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Database\Contracts\Query\Expression;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;

abstract class Grammar
{
    use Macroable;

    /**
     * The connection used for escaping values.
     */
    protected Connection $connection;

    /**
     * Create a new grammar instance.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Wrap an array of values.
     *
     * @param  array<Expression|string>  $values
     * @return array<string>
     */
    public function wrapArray(array $values): array
    {
        return array_map($this->wrap(...), $values);
    }

    /**
     * Wrap a table in keyword identifiers.
     */
    public function wrapTable(Expression|string $table, ?string $prefix = null): string
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        $prefix ??= $this->connection->getTablePrefix();

        // If the table being wrapped has an alias we'll need to separate the pieces
        // so we can prefix the table and then wrap each of the segments on their
        // own and then join these both back together using the "as" connector.
        if (stripos($table, ' as ') !== false) {
            return $this->wrapAliasedTable($table, $prefix);
        }

        // If the table being wrapped has a custom schema name specified, we need to
        // prefix the last segment as the table name then wrap each segment alone
        // and eventually join them both back together using the dot connector.
        if (str_contains($table, '.')) {
            $table = substr_replace($table, '.'.$prefix, strrpos($table, '.'), 1);

            return (new Collection(explode('.', $table)))
                ->map($this->wrapValue(...))
                ->implode('.');
        }

        return $this->wrapValue($prefix.$table);
    }

    /**
     * Wrap a value in keyword identifiers.
     */
    public function wrap(Expression|string $value): string
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        // If the value being wrapped has a column alias we will need to separate out
        // the pieces so we can wrap each of the segments of the expression on its
        // own, and then join these both back together using the "as" connector.
        if (stripos($value, ' as ') !== false) {
            return $this->wrapAliasedValue($value);
        }

        // If the given value is a JSON selector we will wrap it differently than a
        // traditional value. We will need to split this path and wrap each part
        // wrapped, etc. Otherwise, we will simply wrap the value as a string.
        if ($this->isJsonSelector($value)) {
            return $this->wrapJsonSelector($value);
        }

        return $this->wrapSegments(explode('.', $value));
    }

    /**
     * Wrap a value that has an alias.
     */
    protected function wrapAliasedValue(string $value): string
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0]).' as '.$this->wrapValue($segments[1]);
    }

    /**
     * Wrap a table that has an alias.
     */
    protected function wrapAliasedTable(string $value, ?string $prefix = null): string
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        $prefix ??= $this->connection->getTablePrefix();

        return $this->wrapTable($segments[0], $prefix).' as '.$this->wrapValue($prefix.$segments[1]);
    }

    /**
     * Wrap the given value segments.
     *
     * @param  list<string>  $segments
     */
    protected function wrapSegments(array $segments): string
    {
        return (new Collection($segments))->map(function ($segment, $key) use ($segments) {
            return $key == 0 && count($segments) > 1
                ? $this->wrapTable($segment)
                : $this->wrapValue($segment);
        })->implode('.');
    }

    /**
     * Wrap a single string in keyword identifiers.
     */
    protected function wrapValue(string $value): string
    {
        if ($value !== '*') {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /**
     * Wrap the given JSON selector.
     *
     * @throws RuntimeException
     */
    protected function wrapJsonSelector(string $value): string
    {
        throw new RuntimeException('This database engine does not support JSON operations.');
    }

    /**
     * Determine if the given string is a JSON selector.
     */
    protected function isJsonSelector(string $value): bool
    {
        return str_contains($value, '->');
    }

    /**
     * Convert an array of column names into a delimited string.
     *
     * @param  array<Expression|string>  $columns
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map($this->wrap(...), $columns));
    }

    /**
     * Create query parameter place-holders for an array.
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map($this->parameter(...), $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     */
    public function parameter(mixed $value): string
    {
        return $this->isExpression($value) ? $this->getValue($value) : '?';
    }

    /**
     * Quote the given string literal.
     *
     * @param  string|array<string>  $value
     */
    public function quoteString(string|array $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, __FUNCTION__], $value));
        }

        return "'$value'";
    }

    /**
     * Escapes a value for safe SQL embedding.
     */
    public function escape(string|float|int|bool|null $value, bool $binary = false): string
    {
        return $this->connection->escape($value, $binary);
    }

    /**
     * Determine if the given value is a raw expression.
     */
    public function isExpression(mixed $value): bool
    {
        return $value instanceof Expression;
    }

    /**
     * Transforms expressions to their scalar types.
     */
    public function getValue(Expression|string|int|float $expression): string|int|float
    {
        if ($this->isExpression($expression)) {
            return $this->getValue($expression->getValue($this));
        }

        return $expression;
    }

    /**
     * Get the format for database stored dates.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Get the grammar's table prefix.
     *
     * @deprecated Use DB::getTablePrefix()
     */
    public function getTablePrefix(): string
    {
        return $this->connection->getTablePrefix();
    }

    /**
     * Set the grammar's table prefix.
     *
     * @deprecated Use DB::setTablePrefix()
     */
    public function setTablePrefix(string $prefix): static
    {
        $this->connection->setTablePrefix($prefix);

        return $this;
    }
}
