<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Migrations\Migration;

abstract class ConnectionPropertyMigration extends Migration
{
    protected ?string $connection = 'pgsql';
}
