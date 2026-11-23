<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;

abstract class HookedConnectionMigration extends Migration
{
    protected ?string $connection = null {
        get => $this->connection ?? 'pgsql';
    }
}
