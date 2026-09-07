<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Telescope\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Telescope\Database\TelescopeMigrationTestCase;

#[RequiresDatabase('pgsql')]
class TelescopeMigrationTest extends TelescopeMigrationTestCase
{
}
