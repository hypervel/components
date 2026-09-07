<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Database\MariaDb;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Session\Database\DatabaseSessionHandlerTestCase;

#[RequiresDatabase('mariadb')]
#[WithMigration('session')]
class DatabaseSessionHandlerTest extends DatabaseSessionHandlerTestCase
{
}
