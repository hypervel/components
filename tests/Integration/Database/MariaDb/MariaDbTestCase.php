<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

#[RequiresDatabase('mariadb')]
abstract class MariaDbTestCase extends DatabaseTestCase
{
}
