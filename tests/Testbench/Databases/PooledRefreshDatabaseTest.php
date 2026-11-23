<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PDO;

#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
#[WithConfig('database.connections.testing.pool.testing_enabled', true)]
class PooledRefreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Supply the migrated database retained from a previous application.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $saved = new PDO('sqlite::memory:');
        $saved->exec('create table retained_records (value integer)');
        $saved->exec('insert into retained_records values (42)');
        RefreshDatabaseState::$inMemoryConnections['testing'] = $saved;
        RefreshDatabaseState::$migrated = true;
    }

    public function testRestoredDatabaseSurvivesTheSetupCoroutine(): void
    {
        $connection = DB::connection();
        $this->assertSame(1, $connection->transactionLevel());
        $this->assertSame(RefreshDatabaseState::$inMemoryConnections['testing'], $connection->getPdo());
        $this->assertSame(42, $connection->table('retained_records')->value('value'));
    }
}
