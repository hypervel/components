<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Contracts\Events\MigrationEvent;

class NoPendingMigrations implements MigrationEvent
{
    /**
     * Create a new event instance.
     *
     * @param string $method The migration method that was called.
     */
    public function __construct(
        public string $method,
    ) {
    }
}
