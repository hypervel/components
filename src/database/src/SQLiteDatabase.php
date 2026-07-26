<?php

declare(strict_types=1);

namespace Hypervel\Database;

class SQLiteDatabase
{
    /**
     * Determine if the database name is a SQLite URI.
     */
    public static function isUri(string $database): bool
    {
        return str_starts_with($database, 'file:');
    }

    /**
     * Determine if the database name resolves to an in-memory database.
     */
    public static function isInMemory(string $database): bool
    {
        if ($database === ':memory:') {
            return true;
        }

        if (! static::isUri($database)) {
            return false;
        }

        [$path, $query] = array_pad(
            explode('?', substr($database, strlen('file:')), 2),
            2,
            null
        );

        if (rawurldecode($path) === ':memory:') {
            return true;
        }

        parse_str($query ?? '', $parameters);

        return ($parameters['mode'] ?? null) === 'memory';
    }
}
