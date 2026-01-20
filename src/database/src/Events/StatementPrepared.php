<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Connection;
use PDOStatement;

class StatementPrepared
{
    /**
     * Create a new event instance.
     *
     * @param Connection $connection The database connection instance.
     * @param PDOStatement $statement The PDO statement.
     */
    public function __construct(
        public Connection $connection,
        public PDOStatement $statement,
    ) {
    }
}
