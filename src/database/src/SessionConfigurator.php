<?php

declare(strict_types=1);

namespace Hypervel\Database;

use PDO;

interface SessionConfigurator
{
    /**
     * Return the complete desired state identity for the connection.
     *
     * Return null only when this configurator does not apply to the connection.
     * The value must completely identify the state that apply() establishes.
     * This method runs on every synchronized PDO hand-out and must not execute
     * database work.
     */
    public function state(Connection $connection): ?string;

    /**
     * Apply the complete desired state to the physical database session.
     *
     * Use the given PDO directly. Calling Connection query APIs from this
     * method is reentrant and fails closed.
     */
    public function apply(PDO $pdo, string $state, Connection $connection): void;
}
