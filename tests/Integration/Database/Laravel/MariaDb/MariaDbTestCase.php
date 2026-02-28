<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
#[RequiresDatabase('mariadb')]
abstract class MariaDbTestCase extends DatabaseTestCase
{
}
