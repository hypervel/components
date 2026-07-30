<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\DbPoolHeartbeatTest;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PDO;
use PDOStatement;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;

use function Hypervel\Coroutine\run;

class DbPoolHeartbeatTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $databasePath;

    protected string $databaseDirectory;

    /**
     * @var InspectableHeartbeatDbPool[]
     */
    protected array $pools = [];

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('app.stdout_log.level', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseDirectory = ParallelTesting::tempDir('DbPoolHeartbeatTest');
        $files = new Filesystem;
        $files->deleteDirectory($this->databaseDirectory);
        $files->ensureDirectoryExists($this->databaseDirectory);
        $this->databasePath = $this->databaseDirectory . '/database.sqlite';
        touch($this->databasePath);

        $this->app->instance('db.connector.sqlite', new SQLiteConnector);
    }

    protected function tearDown(): void
    {
        foreach ($this->pools as $pool) {
            run(fn () => $pool->close());
        }

        (new Filesystem)->deleteDirectory($this->databaseDirectory);

        parent::tearDown();
    }

    public function testDisabledHeartbeatDoesNotStartTimer(): void
    {
        $pool = $this->createPool([
            'heartbeat' => -1,
        ]);

        $this->assertSame(0, $pool->heartbeatTimerCount());
    }

    public function testEnabledHeartbeatStartsTimerAndCloseClearsIt(): void
    {
        $pool = $this->createPool([
            'heartbeat' => 0.001,
        ]);

        $this->assertSame(1, $pool->heartbeatTimerCount());

        run(fn () => $pool->close());

        $this->assertSame(0, $pool->heartbeatTimerCount());
    }

    public function testHeartbeatKeepsMinimumConnectionsWarmAndEvictsExpiredExtras(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 3,
                'heartbeat' => -1,
                'max_idle_time' => 1.0,
            ]);

            $connections = [
                $pool->get(),
                $pool->get(),
                $pool->get(),
            ];

            foreach ($connections as $connection) {
                $connection->getConnection()->getPdo();
                $connection->release();
                $this->ageReleasedConnection($connection);
            }

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(1, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatValidationKeepsMinimumConnectionCheckoutValid(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_idle_time' => 1.0,
            ]);

            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();

            $pooledConnection->release();
            $this->ageReleasedConnection($pooledConnection);

            $pool->runHeartbeatForTest();

            /** @var PooledConnection $nextPooledConnection */
            $nextPooledConnection = $pool->get();

            $this->assertSame($connection, $nextPooledConnection->getConnection());
            $this->assertSame($pdo, $nextPooledConnection->getConnection()->getPdo());

            $nextPooledConnection->release();
        });
    }

    public function testHeartbeatDiscardsLifetimeExpiredIdleConnectionBeforePinging(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ], LifetimeExpiredPingTrackingDbPool::class);

            $pooledConnection = $pool->get();
            $this->assertInstanceOf(LifetimeExpiredPingTrackingPooledConnection::class, $pooledConnection);

            $connection = $pooledConnection->getConnection();
            $pooledConnection->release();

            $this->ageConnectionGeneration($pooledConnection);

            $pool->runHeartbeatForTest();

            $this->assertFalse($pooledConnection->pingCalled);
            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());

            $nextPooledConnection = $pool->get();

            $this->assertNotSame($connection, $nextPooledConnection->getConnection());

            $nextPooledConnection->release();
        });
    }

    public function testHeartbeatDoesNotRecycleBorrowedLifetimeExpiredConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'max_lifetime' => 1.0,
            ]);

            $borrowed = $pool->get();

            $this->ageConnectionGeneration($borrowed);

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $borrowed->getConnection()->selectOne('SELECT 1 as result')->result);
            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());

            $borrowed->release();
        });
    }

    public function testHeartbeatDoesNotRealizeLazyPdoClosures(): void
    {
        run(function () {
            $pool = $this->createPool([
                'heartbeat' => -1,
            ]);

            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();

            $this->assertInstanceOf(Closure::class, $connection->getRawPdo());

            $pooledConnection->release();
            $pool->runHeartbeatForTest();

            $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        });
    }

    public function testHeartbeatPingDoesNotFireQueryInstrumentation(): void
    {
        run(function () {
            $pool = $this->createPool([
                'heartbeat' => -1,
            ]);

            $events = 0;
            $this->app->make(Dispatcher::class)->listen(QueryExecuted::class, function () use (&$events) {
                ++$events;
            });

            $pooledConnection = $pool->get();
            $connection = $pooledConnection->getConnection();
            $connection->getPdo();
            $pooledConnection->release();

            $connection->enableQueryLog();
            $connection->whenQueryingForLongerThan(-1, function () use (&$events) {
                ++$events;
            });

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $events);
            $this->assertSame([], $connection->getQueryLog());
            $this->assertSame(0.0, $connection->totalQueryDuration());
        });
    }

    public function testHeartbeatOnlyTouchesIdleConnections(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 2,
                'heartbeat' => -1,
                'max_idle_time' => 1.0,
            ]);

            $borrowed = $pool->get();
            $idle = $pool->get();
            $idle->getConnection()->getPdo();
            $idle->release();
            $this->ageReleasedConnection($idle);

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $borrowed->getConnection()->selectOne('SELECT 1 as result')->result);
            $this->assertSame(1, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());

            $borrowed->release();
        });
    }

    public function testFailedHeartbeatPingDiscardsConnectionBelowMinimum(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], FailingHeartbeatDbPool::class);

            $pooledConnection = $pool->get();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatDiscardsInvalidIdleConnectionBelowMinimum(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ]);

            $pooledConnection = $pool->get();
            $pooledConnection->release();

            (new ReflectionProperty(PooledConnection::class, 'invalid'))->setValue($pooledConnection, true);

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatPingTimeoutDiscardsWithoutRequeueingLateCompletion(): void
    {
        run(function () {
            SlowHeartbeatPdo::$coroutineId = null;

            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
                'heartbeat_timeout' => 0.001,
            ], SlowHeartbeatDbPool::class);

            $pooledConnection = $pool->get();
            $pooledConnection->release();

            $startedAt = microtime(true);
            $pool->runHeartbeatForTest();
            $elapsed = microtime(true) - $startedAt;

            $this->assertLessThan(0.2, $elapsed);
            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
            $this->assertIsInt(SlowHeartbeatPdo::$coroutineId);
            $deadline = microtime(true) + 0.1;
            while (Coroutine::exists(SlowHeartbeatPdo::$coroutineId) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFalse(Coroutine::exists(SlowHeartbeatPdo::$coroutineId));

            usleep(100000);

            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testSuccessfulHeartbeatPingAfterCloseDiscardsConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], ClosingHeartbeatDbPool::class);

            $pooledConnection = $pool->get();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    public function testHeartbeatDiscardOnlyDecrementsOnceWhenLoggerThrows(): void
    {
        run(function () {
            $this->app->instance(StdoutLoggerInterface::class, new ThrowingHeartbeatLogger);

            $pool = $this->createPool([
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ], OpenTransactionFailingHeartbeatDbPool::class);

            $pooledConnection = $pool->get();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getCurrentConnections());
            $this->assertSame(0, $pool->getConnectionsInChannel());
        });
    }

    /**
     * @param array<string, mixed> $poolOptions
     */
    protected function createPool(array $poolOptions = [], string $poolClass = InspectableHeartbeatDbPool::class): InspectableHeartbeatDbPool
    {
        $this->app->make('config')->set('database.connections.heartbeat_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
                ...$poolOptions,
            ],
        ]);

        $pool = new $poolClass($this->app, 'heartbeat_test');
        $this->pools[] = $pool;

        return $pool;
    }

    protected function ageReleasedConnection(PooledConnection $connection): void
    {
        $lastReleaseTime = new ReflectionProperty(PooledConnection::class, 'lastReleaseTime');
        $lastUseTime = new ReflectionProperty(PooledConnection::class, 'lastUseTime');

        $lastReleaseTime->setValue($connection, hrtime(true) / 1e9 - 5.0);
        $lastUseTime->setValue($connection, hrtime(true) / 1e9 - 5.0);
    }

    protected function ageConnectionGeneration(PooledConnection $connection): void
    {
        (new ReflectionProperty(PooledConnection::class, 'createdAt'))->setValue($connection, hrtime(true) / 1e9 - 5.0);

        $lifetimeExpiresAt = new ReflectionProperty(PooledConnection::class, 'lifetimeExpiresAt');

        if ($lifetimeExpiresAt->getValue($connection) > 0.0) {
            $lifetimeExpiresAt->setValue($connection, hrtime(true) / 1e9 - 1.0);
        }
    }
}

class InspectableHeartbeatDbPool extends DbPool
{
    public function runHeartbeatForTest(): void
    {
        $this->heartbeat();
    }

    public function heartbeatTimerCount(): int
    {
        $timer = (new ReflectionProperty(DbPool::class, 'heartbeatTimer'))->getValue($this);

        return $timer === null ? 0 : count((new ClassInvoker($timer))->coroutines);
    }
}

class FailingHeartbeatDbPool extends InspectableHeartbeatDbPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new FailingHeartbeatPooledConnection($this->container, $this, $this->config);
    }
}

class FailingHeartbeatPooledConnection extends PooledConnection
{
    public function ping(float $timeout): bool
    {
        return false;
    }
}

class LifetimeExpiredPingTrackingDbPool extends InspectableHeartbeatDbPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new LifetimeExpiredPingTrackingPooledConnection($this->container, $this, $this->config);
    }
}

class LifetimeExpiredPingTrackingPooledConnection extends PooledConnection
{
    public bool $pingCalled = false;

    public function ping(float $timeout): bool
    {
        $this->pingCalled = true;

        return true;
    }
}

class ClosingHeartbeatDbPool extends InspectableHeartbeatDbPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new ClosingHeartbeatPooledConnection($this->container, $this, $this->config);
    }
}

class ClosingHeartbeatPooledConnection extends PooledConnection
{
    public function ping(float $timeout): bool
    {
        $this->pool->close();

        return true;
    }
}

class OpenTransactionFailingHeartbeatDbPool extends InspectableHeartbeatDbPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new OpenTransactionFailingHeartbeatPooledConnection($this->container, $this, $this->config);
    }
}

class OpenTransactionFailingHeartbeatPooledConnection extends FailingHeartbeatPooledConnection
{
    public function hasOpenTransaction(): bool
    {
        return true;
    }
}

class ThrowingHeartbeatLogger extends AbstractLogger implements StdoutLoggerInterface
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('Logger failed.');
    }
}

class SlowHeartbeatDbPool extends InspectableHeartbeatDbPool
{
    protected function createConnection(): ConnectionInterface
    {
        return new SlowHeartbeatPooledConnection($this->container, $this, $this->config);
    }
}

class SlowHeartbeatPooledConnection extends PooledConnection
{
    protected function getOpenPdos(): array
    {
        return [new SlowHeartbeatPdo];
    }
}

class SlowHeartbeatPdo extends PDO
{
    public static ?int $coroutineId = null;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        self::$coroutineId = Coroutine::id();

        usleep(500000);

        return false;
    }
}
