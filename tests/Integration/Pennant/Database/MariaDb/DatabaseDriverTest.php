<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Pennant\Database\MySqlFamilyDatabaseDriverTestCase;

#[RequiresDatabase('mariadb')]
class DatabaseDriverTest extends MySqlFamilyDatabaseDriverTestCase
{
}
