<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Closure;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;

class DatabasePresenceVerifier implements DatabasePresenceVerifierInterface
{
    /**
     * The database connection to use.
     */
    protected ?string $connection = null;

    /**
     * Create a new database presence verifier.
     *
     * @param ConnectionResolverInterface $db the database connection instance
     */
    public function __construct(
        protected ConnectionResolverInterface $db
    ) {
    }

    /**
     * Count the number of objects in a collection having the given value.
     */
    public function getCount(string $collection, string $column, mixed $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
    {
        $query = $this->table($collection)->where($column, '=', $value);

        if (! is_null($excludeId) && $excludeId !== 'NULL') {
            $query->where($idColumn ?: 'id', '<>', $excludeId);
        }

        return $this->addConditions($query, $extra)->count();
    }

    /**
     * Count the number of objects in a collection with the given values.
     */
    public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
    {
        $query = $this->table($collection)->whereIn($column, $values);

        return $this->addConditions($query, $extra)->distinct()->count($column);
    }

    /**
     * Add the given conditions to the query.
     */
    protected function addConditions(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $key => $value) {
            if ($value instanceof Closure) {
                $query->where(function ($query) use ($value) {
                    $value($query);
                });
            } else {
                $this->addWhere($query, $key, $value);
            }
        }

        return $query;
    }

    /**
     * Add a "where" clause to the given query.
     */
    protected function addWhere(Builder $query, string $key, mixed $extraValue): void
    {
        $extraValue = (string) $extraValue;

        if ($extraValue === 'NULL') {
            $query->whereNull($key);
        } elseif ($extraValue === 'NOT_NULL') {
            $query->whereNotNull($key);
        } elseif (str_starts_with($extraValue, '!')) {
            $query->where($key, '!=', mb_substr($extraValue, 1));
        } else {
            $query->where($key, $extraValue);
        }
    }

    /**
     * Get a query builder for the given table.
     */
    protected function table(string $table): Builder
    {
        return $this->db->connection($this->connection)->table($table)->useWritePdo();
    }

    /**
     * Set the connection to be used.
     */
    public function setConnection(?string $connection): void
    {
        $this->connection = $connection;
    }
}
