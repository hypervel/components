<?php

declare(strict_types=1);

namespace Hypervel\Database\Connectors;

use PDO;

interface ConnectorInterface
{
    /**
     * Establish a database connection.
     */
    public function connect(array $config): PDO;
}
