<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Telescope\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Telescope\Database\TelescopeMigrationTestCase;

#[RequiresDatabase('mariadb')]
class TelescopeMigrationTest extends TelescopeMigrationTestCase
{
}
