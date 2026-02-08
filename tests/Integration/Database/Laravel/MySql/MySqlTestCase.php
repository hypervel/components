<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\MySql;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
#[RequiresDatabase('mysql')]
abstract class MySqlTestCase extends DatabaseTestCase
{
}
