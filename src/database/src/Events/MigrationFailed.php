<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Migrations\Migration;
use Throwable;

class MigrationFailed extends MigrationEvent
{
    /**
     * Create a new event instance.
     *
     * @param Throwable $exception the exception that caused the migration to fail
     */
    public function __construct(
        Migration $migration,
        string $method,
        ?string $name,
        public Throwable $exception,
    ) {
        parent::__construct($migration, $method, $name);
    }
}
