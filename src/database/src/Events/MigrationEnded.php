<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Migrations\Migration;

class MigrationEnded extends MigrationEvent
{
    /**
     * The number of milliseconds the migration took to run.
     */
    public ?float $duration;

    /**
     * Create a new event instance.
     */
    public function __construct(Migration $migration, string $method, ?string $name = null, ?float $duration = null)
    {
        parent::__construct($migration, $method, $name);

        $this->duration = $duration;
    }
}
