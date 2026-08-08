<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\RateLimiter\Database\Postgres;

use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\RateLimiter\Database\DatabaseStoreTestCase;

#[RequiresDatabase('pgsql')]
#[WithMigration]
class DatabaseStoreTest extends DatabaseStoreTestCase
{
}
