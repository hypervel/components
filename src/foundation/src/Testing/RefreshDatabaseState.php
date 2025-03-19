<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use PDO;

class RefreshDatabaseState
{
    /**
     * The current SQLite in-memory database connections.
     *
     * @var array<string, PDO>
     */
    public static array $inMemoryConnections = [];

    /**
     * Indicates if the test database has been migrated.
     */
    public static bool $migrated = false;

    /**
     * Indicates if a lazy refresh hook has been invoked.
     */
    public static bool $lazilyRefreshed = false;
}
