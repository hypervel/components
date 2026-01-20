<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Contracts\Events\MigrationEvent;

abstract class MigrationsEvent implements MigrationEvent
{
    /**
     * Create a new event instance.
     *
     * @param string $method The migration method that was invoked.
     * @param array<string, mixed> $options The options provided when the migration method was invoked.
     */
    public function __construct(
        public string $method,
        public array $options = [],
    ) {
    }
}
