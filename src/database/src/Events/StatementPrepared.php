<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\PdoConnection;
use PDOStatement;

class StatementPrepared
{
    /**
     * Create a new event instance.
     *
     * @param PdoConnection $connection the database connection instance
     * @param PDOStatement $statement the PDO statement
     */
    public function __construct(
        public PdoConnection $connection,
        public PDOStatement $statement,
    ) {
    }
}
