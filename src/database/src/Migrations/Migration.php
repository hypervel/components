<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

abstract class Migration
{
    /**
     * The name of the database connection to use.
     */
    protected ?string $connection = null;

    /**
     * Enables, if supported, wrapping the migration within a transaction.
     */
    public bool $withinTransaction = true;

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection;
    }

    /**
     * Determine if this migration should run.
     */
    public function shouldRun(): bool
    {
        return true;
    }
}
