<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\ConcurrencyErrorDetector;
use Hypervel\Database\Connection;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;

use function Hypervel\Coroutine\parallel;

class ConnectionLockTimeoutTest extends DatabaseTestCase
{
    private const string CONNECTION_NAME = 'lock_timeout_test';

    private const string LOCK_TABLE = 'connection_lock_timeout_probes';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $defaultConnection = $config->string('database.default');
        $connection = $config->array("database.connections.{$defaultConnection}");

        $connection['lock_timeout'] = 1;
        $connection['pool'] = [
            'testing_enabled' => true,
            'min_connections' => 1,
            'max_connections' => 1,
            'heartbeat' => -1,
        ];

        $config->set('database.connections.' . self::CONNECTION_NAME, $connection);
    }

    public function testLockTimeoutIsAppliedWhenPooledConnectionsAreCreatedAndReconnected(): void
    {
        if ($this->driver === 'sqlite') {
            $this->markTestSkipped('SQLite uses its existing busy_timeout connection option.');
        }

        $pool = new DbPool($this->app, self::CONNECTION_NAME);

        /** @var PooledConnection $pooledConnection */
        $pooledConnection = $pool->get();

        try {
            $connection = $pooledConnection->getConnection();

            $this->assertSame($this->expectedLockTimeouts(1_000), $this->lockTimeouts($connection));

            match ($this->driver) {
                'mysql', 'mariadb' => $connection->statement(
                    'SET SESSION innodb_lock_wait_timeout=7, SESSION lock_wait_timeout=7'
                ),
                'pgsql' => $connection->statement("SET lock_timeout='7s'"),
            };

            $this->assertSame($this->expectedLockTimeouts(7_000), $this->lockTimeouts($connection));

            $pooledConnection->reconnect();

            $this->assertSame(
                $this->expectedLockTimeouts(1_000),
                $this->lockTimeouts($pooledConnection->getConnection()),
            );
        } finally {
            $pooledConnection->release();
            $pool->close();
        }
    }

    public function testLockTimeoutErrorsAreClassifiedAndRetried(): void
    {
        if ($this->driver === 'sqlite') {
            $this->markTestSkipped('SQLite uses its existing busy_timeout connection option.');
        }

        $holderPool = new DbPool($this->app, self::CONNECTION_NAME);
        $contenderPool = new DbPool($this->app, self::CONNECTION_NAME);

        /** @var PooledConnection $setupConnection */
        $setupConnection = $holderPool->get();

        try {
            $schema = $setupConnection->getConnection()->getSchemaBuilder();
            $schema->dropIfExists(self::LOCK_TABLE);
            $schema->create(self::LOCK_TABLE, function (Blueprint $table): void {
                $table->increments('id');
            });
            $setupConnection->getConnection()->table(self::LOCK_TABLE)->insert(['id' => 1]);
        } finally {
            $setupConnection->release();
        }

        $lockAcquired = new Channel(1);
        $releaseLock = new Channel(1);

        try {
            [$holderCompleted, $contenderResult] = parallel([
                function () use ($holderPool, $lockAcquired, $releaseLock): bool {
                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $holderPool->get();
                    $connection = $pooledConnection->getConnection();

                    try {
                        $connection->beginTransaction();
                        $connection->table(self::LOCK_TABLE)->where('id', 1)->lockForUpdate()->first();
                        $lockAcquired->push(true);
                        $releaseLock->pop(5);

                        return true;
                    } finally {
                        if ($connection->transactionLevel() > 0) {
                            $connection->rollBack();
                        }

                        $pooledConnection->release();
                    }
                },
                function () use ($contenderPool, $lockAcquired, $releaseLock): array {
                    $lockAcquired->pop(5);

                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $contenderPool->get();
                    $connection = $pooledConnection->getConnection();

                    try {
                        $classified = false;
                        $lockWaitStartedAt = microtime(true);

                        try {
                            $connection->table(self::LOCK_TABLE)->where('id', 1)->lockForUpdate()->first();
                        } catch (QueryException $exception) {
                            $classified = (new ConcurrencyErrorDetector)->causedByConcurrencyError($exception);
                        }

                        $lockWaitSeconds = microtime(true) - $lockWaitStartedAt;

                        $attempts = 0;
                        $id = $connection->transaction(
                            function (Connection $connection) use (&$attempts, $releaseLock): int {
                                ++$attempts;

                                if ($attempts === 2) {
                                    $releaseLock->push(true);
                                }

                                return (int) $connection->table(self::LOCK_TABLE)
                                    ->where('id', 1)
                                    ->lockForUpdate()
                                    ->value('id');
                            },
                            attempts: 2,
                        );

                        return [
                            'classified' => $classified,
                            'attempts' => $attempts,
                            'id' => $id,
                            'lock_wait_seconds' => $lockWaitSeconds,
                        ];
                    } finally {
                        $pooledConnection->release();
                    }
                },
            ]);

            $this->assertTrue($holderCompleted);
            $this->assertTrue($contenderResult['classified']);
            $this->assertSame(2, $contenderResult['attempts']);
            $this->assertSame(1, $contenderResult['id']);
            $this->assertLessThan(3.0, $contenderResult['lock_wait_seconds']);
        } finally {
            /** @var PooledConnection $cleanupConnection */
            $cleanupConnection = $holderPool->get();

            try {
                $cleanupConnection->getConnection()->getSchemaBuilder()->dropIfExists(self::LOCK_TABLE);
            } finally {
                $cleanupConnection->release();
                $holderPool->close();
                $contenderPool->close();
            }
        }
    }

    /**
     * Read the current connection's lock timeouts in milliseconds.
     *
     * @return array<string, int>
     */
    private function lockTimeouts(Connection $connection): array
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => [
                'row' => 1_000 * (int) $connection
                    ->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS lock_timeout')
                    ->lock_timeout,
                'metadata' => 1_000 * (int) $connection
                    ->selectOne('SELECT @@SESSION.lock_wait_timeout AS lock_timeout')
                    ->lock_timeout,
            ],
            'pgsql' => [
                'statement' => (int) $connection
                    ->selectOne("SELECT setting FROM pg_settings WHERE name = 'lock_timeout'")
                    ->setting,
            ],
        };
    }

    /**
     * Get the expected lock-timeout shape for the active database driver.
     *
     * @return array<string, int>
     */
    private function expectedLockTimeouts(int $milliseconds): array
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => ['row' => $milliseconds, 'metadata' => $milliseconds],
            'pgsql' => ['statement' => $milliseconds],
        };
    }
}
