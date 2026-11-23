<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabaseState;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

#[ResetRefreshDatabaseState]
#[WithConfig('database.default', 'testing')]
#[WithConfig('database.connections.testing.pool.testing_enabled', true)]
class PooledLazilyRefreshDatabaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Supply the retained database before lazy callbacks are registered.
     */
    protected function defineEnvironment(Application $app): void
    {
        $saved = new PDO('sqlite::memory:');
        $saved->exec('create table retained_records (value integer)');
        $saved->exec('insert into retained_records values (42)');
        RefreshDatabaseState::$inMemoryConnections['testing'] = $saved;
        RefreshDatabaseState::$migrated = true;
    }

    #[DataProvider('firstQueries')]
    public function testRestoresTheDatabaseBeforeTheFirstQuery(string $scope): void
    {
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(RefreshDatabaseState::$inMemoryConnections['testing'], $connection->getPdo());
        $query = fn (): mixed => $connection->table('retained_records')->value('value');

        $this->assertSame(42, match ($scope) {
            'pinned' => $connection->withPinnedSession($query),
            'transaction' => $connection->transaction($query),
            default => $query(),
        });
        $this->assertSame(1, $connection->transactionLevel());
    }

    /**
     * Provide first-query scopes that must share the restored database.
     */
    public static function firstQueries(): array
    {
        return ['ordinary query' => ['ordinary'], 'explicitly pinned query' => ['pinned'], 'transaction first' => ['transaction']];
    }
}
