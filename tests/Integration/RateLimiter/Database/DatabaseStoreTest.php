<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\RateLimiter\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;

#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
#[WithMigration]
class DatabaseStoreTest extends DatabaseStoreTestCase
{
}
