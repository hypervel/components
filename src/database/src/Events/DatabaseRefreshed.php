<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Contracts\Database\Events\MigrationEvent;

class DatabaseRefreshed implements MigrationEvent
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?string $database = null,
        public bool $seeding = false,
    ) {
    }
}
