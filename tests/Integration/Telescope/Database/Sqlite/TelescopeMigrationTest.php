<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Telescope\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Telescope\Database\TelescopeMigrationTestCase;

#[RequiresDatabase('sqlite')]
class TelescopeMigrationTest extends TelescopeMigrationTestCase
{
}
