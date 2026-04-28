<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Foundation\Testing\DatabaseMigrations;

class EloquentTransactionWithAfterCommitUsingDatabaseMigrationsTest extends DatabaseTestCase
{
    use DatabaseMigrations;
    use EloquentTransactionWithAfterCommitTests;

    protected function afterRefreshingDatabase(): void
    {
        $this->createTransactionTestTables();
    }
}
