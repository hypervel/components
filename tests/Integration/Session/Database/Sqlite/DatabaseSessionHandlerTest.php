<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Database\Sqlite;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Session\Database\DatabaseSessionHandlerTestCase;

#[RequiresDatabase('sqlite')]
#[WithMigration('session')]
class DatabaseSessionHandlerTest extends DatabaseSessionHandlerTestCase
{
}
