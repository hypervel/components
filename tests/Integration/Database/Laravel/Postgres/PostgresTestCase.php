<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
#[RequiresDatabase('pgsql')]
abstract class PostgresTestCase extends DatabaseTestCase
{
}
