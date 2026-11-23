<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;

abstract class ConnectionWritingMigration extends Migration
{
    /**
     * Create a new migration instance.
     */
    public function __construct()
    {
        $this->connection = config('schema_aggregator.connection');
    }
}
