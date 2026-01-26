<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Foundation\Testing\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

#[RequiresDatabase('pgsql')]
abstract class PostgresTestCase extends DatabaseTestCase
{
}
