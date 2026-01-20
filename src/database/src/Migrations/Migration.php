<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Database\ConnectionResolverInterface;
use Hypervel\Context\ApplicationContext;

abstract class Migration
{
    /**
     * Enables, if supported, wrapping the migration within a transaction.
     */
    public bool $withinTransaction = true;

    /**
     * The name of the database connection to use.
     */
    protected ?string $connection = null;

    /**
     * Get the migration connection name.
     */
    public function getConnection(): string
    {
        if ($connection = $this->connection) {
            return $connection;
        }

        return ApplicationContext::getContainer()
            ->get(ConnectionResolverInterface::class)
            ->getDefaultConnection();
    }
}
