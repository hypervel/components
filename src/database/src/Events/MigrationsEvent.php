<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Contracts\Database\Events\MigrationEvent;

abstract class MigrationsEvent implements MigrationEvent
{
    /**
     * Create a new event instance.
     *
     * @param string $method the migration method that was invoked
     * @param array<string, mixed> $options the options provided when the migration method was invoked
     */
    public function __construct(
        public string $method,
        public array $options = [],
    ) {
    }
}
