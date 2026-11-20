<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Bus\Database;

use Hypervel\Testbench\Attributes\RequiresDatabase;

#[RequiresDatabase(['mysql', 'mariadb', 'pgsql'])]
class DatabaseBatchRepositoryTest extends DatabaseBatchRepositoryTestCase
{
}
