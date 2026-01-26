<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Contracts\Database\Events\MigrationEvent;

class MigrationSkipped implements MigrationEvent
{
    /**
     * Create a new event instance.
     *
     * @param string $migrationName the name of the migration that was skipped
     */
    public function __construct(
        public string $migrationName,
    ) {
    }
}
