<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Contracts\Database\Events\MigrationEvent as MigrationEventContract;
use Hypervel\Database\Migrations\Migration;

abstract class MigrationEvent implements MigrationEventContract
{
    /**
     * A migration instance.
     */
    public Migration $migration;

    /**
     * The migration method that was called.
     */
    public string $method;

    /**
     * Create a new event instance.
     */
    public function __construct(Migration $migration, string $method)
    {
        $this->method = $method;
        $this->migration = $migration;
    }
}
