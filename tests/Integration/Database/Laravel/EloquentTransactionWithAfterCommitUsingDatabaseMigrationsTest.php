<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentTransactionWithAfterCommitUsingDatabaseMigrationsTest extends DatabaseTestCase
{
    use DatabaseMigrations;
    use EloquentTransactionWithAfterCommitTests;

    protected function afterRefreshingDatabase(): void
    {
        $this->createTransactionTestTables();
    }
}
