<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ConnectionEstablishedTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $directory;

    /**
     * Define the application's environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $this->directory = ParallelTesting::tempDir('ConnectionEstablishedTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->directory);
        $files->ensureDirectoryExists($this->directory);

        foreach (['read', 'write'] as $role) {
            $pdo = new PDO('sqlite:' . $this->directory . '/' . $role . '.sqlite');
            $pdo->exec('create table marker (value text)');
            $pdo->exec("insert into marker values ('{$role}')");
        }

        $config = $app->make('config');
        $config->set('app.stdout_log.level', []);
        $config->set('database.connections.established', [
            'driver' => 'sqlite',
            'read' => ['database' => $this->directory . '/read.sqlite'],
            'write' => ['database' => $this->directory . '/write.sqlite'],
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.05,
                'heartbeat_interval' => null,
                'idle_check_interval' => null,
            ],
        ]);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    #[TestWith([true, 'established', 'read'])]
    #[TestWith([true, 'established::read', 'read'])]
    #[TestWith([true, 'established::write', 'write'])]
    #[TestWith([false, 'established', 'read'])]
    #[TestWith([false, 'established::read', 'read'])]
    #[TestWith([false, 'established::write', 'write'])]
    public function testListenerResolvesThePreparedConnection(bool $pooled, string $name, string $endpoint): void
    {
        config(['database.connections.established.pool.testing_enabled' => $pooled]);
        $calls = 0;
        $this->app->make('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($name, $endpoint, &$calls): void {
            ++$calls;
            $this->assertSame('established', $event->connectionName);
            $this->assertSame($name, $event->connection->getNameWithReadWriteType());
            $this->assertSame($event->connection, DB::connection($name));
            $this->assertSame($endpoint, DB::connection($name)->table('marker')->value('value'));
        });

        $this->runInCoroutine(fn () => DB::connection($name));

        $this->assertSame(1, $calls);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testSharedMemoryAliasesResolveTheRegisteredConnection(bool $pooled): void
    {
        config(['database.connections.shared' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => ['testing_enabled' => $pooled, 'wait_timeout' => 0.05],
        ]]);
        $calls = 0;
        $this->app->make('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$calls): void {
            ++$calls;
            $this->assertSame($event->connection, DB::connection('shared'));
            $this->assertSame($event->connection, DB::connection('shared::read'));
            $this->assertSame($event->connection, DB::connection('shared::write'));
            $this->assertSame(1, (int) $event->connection->selectOne('select 1 as value')->value);
        });

        $this->runInCoroutine(fn () => DB::connection('shared::write'));

        $this->assertSame(1, $calls);
    }

    public function testOnlyNewGenerationsNotifyAfterRegistration(): void
    {
        $connections = [];
        $this->app->make('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$connections): void {
            $this->assertSame($event->connection, DB::connection('established::write'));
            $connections[] = $event->connection;
        });

        $first = null;
        $this->runInCoroutine(function () use (&$first): void {
            $first = DB::connection('established::write');
        });
        $this->runInCoroutine(function () use ($first): void {
            $this->assertSame($first, DB::connection('established::write'));
        });
        $this->assertSame([$first], $connections);

        $this->runInCoroutine(fn () => DB::connection('established::write')->reconnect());
        $this->assertSame([$first, $first], $connections);

        $pool = $this->app->make(PoolManager::class)->pool('established');
        $pooled = $pool->borrow();
        try {
            (new ReflectionProperty(PooledConnection::class, 'lifetimeExpiresAt'))->setValue($pooled, 0.0);
        } finally {
            $pooled->release();
        }

        $replacement = null;
        $this->runInCoroutine(function () use (&$replacement): void {
            $replacement = DB::connection('established::write');
        });
        $this->assertNotSame($first, $replacement);
        $this->assertSame([$first, $first, $replacement], $connections);
    }

    #[TestWith([true, false])]
    #[TestWith([true, true])]
    #[TestWith([false, false])]
    #[TestWith([false, true])]
    public function testListenerFailureReleasesOwnershipAndPoolCapacity(bool $pooled, bool $canceled): void
    {
        config(['database.connections.established.pool.testing_enabled' => $pooled]);
        $failure = $canceled ? new CanceledException('Listener canceled') : new RuntimeException('Listener failed');
        $failedConnection = null;
        $calls = 0;
        $this->app->make('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($failure, &$failedConnection, &$calls): void {
            $this->assertSame($event->connection, DB::connection('established::write'));
            if (++$calls === 1) {
                $failedConnection = $event->connection;
                throw $failure;
            }
        });

        $this->runInCoroutine(function () use ($failure, &$failedConnection): void {
            try {
                DB::connection('established::write');
                $this->fail('Expected the listener failure.');
            } catch (Throwable $exception) {
                $this->assertSame($failure, $exception);
            }

            $pool = $this->app->make(PoolManager::class)->pool('established');
            $this->assertSame(0, $pool->getBorrowedCount());
            $this->assertSame(0, $pool->getManagedCount());
            $this->assertInstanceOf(Connection::class, $failedConnection);
            $this->assertNotSame($failedConnection, DB::connection('established::write'));
        });
        $this->assertSame(2, $calls);
    }

    public function testNonCoroutineListenerReusesTheRegisteredConnection(): void
    {
        $calls = 0;
        $this->app->make('events')->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$calls): void {
            ++$calls;
            $this->assertSame($event->connection, DB::connection('established::write'));
        });

        DB::connection('established::write');

        $this->assertSame(1, $calls);
    }
}
