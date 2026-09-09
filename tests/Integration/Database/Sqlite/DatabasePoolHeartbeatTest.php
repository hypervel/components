<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\DatabasePoolHeartbeatTest;

use Closure;
use Hypervel\Contracts\ConnectionPool\Connection;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\run;

class DatabasePoolHeartbeatTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected string $databasePath;

    protected string $databaseDirectory;

    /**
     * @var InspectableHeartbeatDatabasePool[]
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

        $this->databaseDirectory = ParallelTesting::tempDir('DatabasePoolHeartbeatTest');
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
            'heartbeat_interval' => null,
        ]);
        run(fn () => $pool->start());

        $this->assertSame(0, $pool->heartbeatTimerCount());
    }

    public function testEnabledHeartbeatStartsTimerAndCloseClearsIt(): void
    {
        run(function (): void {
            $pool = $this->createPool(['heartbeat_interval' => 60.0]);

            try {
                $this->assertSame(0, $pool->heartbeatTimerCount());
                $pool->start();
                $pool->start();
                $this->assertSame(1, $pool->heartbeatTimerCount());
            } finally {
                $pool->close();
            }

            $pool->start();
            $this->assertSame(0, $pool->heartbeatTimerCount());
        });
    }

    public function testHeartbeatStartupCanBeRetriedAfterTimerCreationFails(): void
    {
        run(function (): void {
            $pool = $this->createPool(['heartbeat_interval' => 60.0]);
            $property = new ReflectionProperty(DatabasePool::class, 'heartbeatTimer');
            $timer = $property->getValue($pool);
            $failure = new RuntimeException('Timer creation failed.');
            $failingTimer = m::mock(Timer::class);
            $failingTimer->shouldReceive('tick')->once()->andThrow($failure);
            $property->setValue($pool, $failingTimer);
            $caught = null;

            try {
                $pool->start();
            } catch (Throwable $exception) {
                $caught = $exception;
            } finally {
                $property->setValue($pool, $timer);
            }

            $this->assertSame($failure, $caught);

            try {
                $pool->start();
                $this->assertSame(1, $pool->heartbeatTimerCount());
            } finally {
                $pool->close();
            }
        });
    }

    public function testReentrantHeartbeatStartupCreatesOnlyOneTimer(): void
    {
        run(function (): void {
            $pool = $this->createPool(['heartbeat_interval' => 60.0]);
            FrameworkCoroutine::afterCreated(static fn () => $pool->start());

            try {
                $pool->start();
                $this->assertSame(1, $pool->heartbeatTimerCount());
            } finally {
                $pool->close();
            }
        });
    }

    public function testCloseDuringHeartbeatStartupClearsTheUnpublishedTimer(): void
    {
        run(function (): void {
            $pool = $this->createPool(['heartbeat_interval' => 60.0]);
            $timers = Timer::stats()['num'];
            FrameworkCoroutine::afterCreated(static fn () => $pool->close());

            try {
                $pool->start();
                $pool->start();

                $this->assertTrue($pool->isClosed());
                $this->assertSame(0, $pool->heartbeatTimerCount());
                $this->assertSame($timers, Timer::stats()['num']);
            } finally {
                $pool->close();
            }
        });
    }

    public function testHeartbeatKeepsMinimumConnectionsWarmAndEvictsExpiredExtras(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 3,
                'heartbeat_interval' => null,
                'max_idle_time' => 1.0,
            ]);

            $connections = [
                $pool->borrow(),
                $pool->borrow(),
                $pool->borrow(),
            ];

            foreach ($connections as $connection) {
                $connection->getConnection()->getPdo();
                $connection->release();
                $this->ageReleasedConnection($connection);
            }

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(1, $pool->getIdleCount());
        });
    }

    public function testHeartbeatValidationKeepsMinimumConnectionCheckoutValid(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
                'max_idle_time' => 1.0,
            ]);

            $pooledConnection = $pool->borrow();
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();

            $pooledConnection->release();
            $this->ageReleasedConnection($pooledConnection);

            $pool->runHeartbeatForTest();

            /** @var PooledConnection $nextPooledConnection */
            $nextPooledConnection = $pool->borrow();

            $this->assertSame($connection, $nextPooledConnection->getConnection());
            $this->assertSame($pdo, $nextPooledConnection->getConnection()->getPdo());

            $nextPooledConnection->release();
        });
    }

    public function testHeartbeatDiscardsLifetimeExpiredIdleConnectionBeforePinging(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
                'max_lifetime' => 1.0,
            ], LifetimeExpiredPingTrackingDatabasePool::class);

            $pooledConnection = $pool->borrow();
            $this->assertInstanceOf(LifetimeExpiredPingTrackingPooledConnection::class, $pooledConnection);

            $connection = $pooledConnection->getConnection();
            $pooledConnection->release();

            $this->ageConnectionGeneration($pooledConnection);

            $pool->runHeartbeatForTest();

            $this->assertFalse($pooledConnection->pingCalled);
            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());

            $nextPooledConnection = $pool->borrow();

            $this->assertNotSame($connection, $nextPooledConnection->getConnection());

            $nextPooledConnection->release();
        });
    }

    public function testHeartbeatDoesNotRecycleBorrowedLifetimeExpiredConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
                'max_lifetime' => 1.0,
            ]);

            $borrowed = $pool->borrow();

            $this->ageConnectionGeneration($borrowed);

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $borrowed->getConnection()->selectOne('SELECT 1 as result')->result);
            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());

            $borrowed->release();
        });
    }

    public function testHeartbeatDoesNotRealizeLazyPdoClosures(): void
    {
        run(function () {
            $pool = $this->createPool([
                'heartbeat_interval' => null,
            ]);

            $pooledConnection = $pool->borrow();
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
                'heartbeat_interval' => null,
            ]);

            $events = 0;
            $this->app->make(Dispatcher::class)->listen(QueryExecuted::class, function () use (&$events) {
                ++$events;
            });

            $pooledConnection = $pool->borrow();
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
                'min_retained_connections' => 1,
                'max_connections' => 2,
                'heartbeat_interval' => null,
                'max_idle_time' => 1.0,
            ]);

            $borrowed = $pool->borrow();
            $idle = $pool->borrow();
            $idle->getConnection()->getPdo();
            $idle->release();
            $this->ageReleasedConnection($idle);

            $pool->runHeartbeatForTest();

            $this->assertSame(1, $borrowed->getConnection()->selectOne('SELECT 1 as result')->result);
            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());

            $borrowed->release();
        });
    }

    public function testFailedHeartbeatPingDiscardsConnectionBelowMinimum(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
            ], FailingHeartbeatDatabasePool::class);

            $pooledConnection = $pool->borrow();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());
        });
    }

    public function testHeartbeatDiscardsInvalidIdleConnectionBelowMinimum(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
            ]);

            $pooledConnection = $pool->borrow();
            $pooledConnection->release();

            (new ReflectionProperty(PooledConnection::class, 'invalid'))->setValue($pooledConnection, true);

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());
        });
    }

    public function testHeartbeatPingTimeoutDiscardsWithoutRequeueingLateCompletion(): void
    {
        run(function () {
            SlowHeartbeatConnection::$coroutineId = null;

            $this->app->make('db.factory')->extend(
                'heartbeat_test',
                static fn (array $config): SlowHeartbeatConnection => new SlowHeartbeatConnection(
                    static fn () => throw new RuntimeException('The slow heartbeat test must not resolve its PDO.'),
                    $config['database'],
                    $config['prefix'],
                    $config,
                )
            );

            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 0.001,
            ]);

            $pooledConnection = $pool->borrow();
            $pooledConnection->release();

            $startedAt = microtime(true);
            $pool->runHeartbeatForTest();
            $elapsed = microtime(true) - $startedAt;

            $this->assertLessThan(0.2, $elapsed);
            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());
            $this->assertIsInt(SlowHeartbeatConnection::$coroutineId);
            $deadline = microtime(true) + 0.1;
            while (Coroutine::exists(SlowHeartbeatConnection::$coroutineId) && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFalse(Coroutine::exists(SlowHeartbeatConnection::$coroutineId));

            usleep(100000);

            $this->assertSame(0, $pool->getIdleCount());
        });
    }

    public function testSuccessfulHeartbeatPingAfterCloseDiscardsConnection(): void
    {
        run(function () {
            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
            ], ClosingHeartbeatDatabasePool::class);

            $pooledConnection = $pool->borrow();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());
        });
    }

    public function testHeartbeatDiscardOnlyDecrementsOnceWhenLoggerThrows(): void
    {
        run(function () {
            $this->app->instance(StdoutLoggerInterface::class, new ThrowingHeartbeatLogger);

            $pool = $this->createPool([
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'heartbeat_interval' => null,
            ], OpenTransactionFailingHeartbeatDatabasePool::class);

            $pooledConnection = $pool->borrow();
            $pooledConnection->release();

            $pool->runHeartbeatForTest();

            $this->assertSame(0, $pool->getManagedCount());
            $this->assertSame(0, $pool->getIdleCount());
        });
    }

    #[DataProvider('heartbeatCancellationPaths')]
    public function testHeartbeatCancellationDisposesOnceAndLeavesLaterIdleConnections(string $path): void
    {
        run(function () use ($path): void {
            $cancellation = new CanceledException('heartbeat canceled');
            $secondary = $path === 'evaluation with failed close'
                ? new RuntimeException('close failed')
                : new CanceledException('secondary close cancellation');
            $logger = m::mock(StdoutLoggerInterface::class);

            if ($path === 'evaluation with failed close') {
                $logger->shouldReceive('error')->once()->with((string) $secondary);
            } else {
                $logger->shouldNotReceive('error');
            }

            $this->app->instance(StdoutLoggerInterface::class, $logger);
            $pool = $this->createPool([], CancellableDisposalDatabasePool::class);
            $first = $pool->borrow();
            $later = $pool->borrow();
            $this->assertInstanceOf(CancellableDisposalPooledConnection::class, $first);
            $this->assertInstanceOf(CancellableDisposalPooledConnection::class, $later);

            if ($path === 'disposal') {
                $first->healthy = false;
                $first->closeFailure = $cancellation;
            } else {
                $first->pingFailure = $cancellation;
                $first->closeFailure = $path === 'evaluation' ? null : $secondary;
            }

            $first->release();
            $later->release();
            $first->closeCount = 0;
            $later->closeCount = 0;
            $caught = null;

            try {
                $pool->runHeartbeatForTest();
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            $this->assertSame($cancellation, $caught);
            $this->assertSame(1, $first->closeCount);
            $this->assertSame(0, $later->closeCount);
            $this->assertSame(0, $later->pingCount);
            $this->assertSame(1, $pool->getManagedCount());
            $this->assertSame(1, $pool->getIdleCount());
        });
    }

    public static function heartbeatCancellationPaths(): array
    {
        return [
            ['evaluation'],
            ['evaluation with canceled close'],
            ['evaluation with failed close'],
            ['disposal'],
        ];
    }

    #[DataProvider('nativeCancellationModes')]
    public function testCanceledHeartbeatSweepStopsItsChildAndPreservesRemainingIdleConnections(bool $throwException): void
    {
        $observed = [];

        run(function () use ($throwException, &$observed): void {
            $this->app->make('db.factory')->extend(
                'heartbeat_test',
                static fn (array $config): CancellableHeartbeatConnection => new CancellableHeartbeatConnection(
                    static fn () => throw new RuntimeException('The heartbeat test must not resolve its PDO.'),
                    $config['database'],
                    $config['prefix'],
                    $config,
                ),
            );
            $pool = $this->createPool();
            $first = $pool->borrow();
            $later = $pool->borrow();
            $firstConnection = $first->getConnection();
            $laterConnection = $later->getConnection();
            $firstConnection->pingStarted = new Channel(1);
            $firstConnection->blocker = new Channel(1);
            $first->release();
            $later->release();
            $caught = null;
            $parent = Coroutine::create(static function () use ($pool, &$caught): void {
                try {
                    $pool->runHeartbeatForTest();
                } catch (Throwable $exception) {
                    $caught = $exception;
                }
            });

            try {
                $observed['started'] = $firstConnection->pingStarted->pop(1.0);
                $observed['canceled'] = Coroutine::cancelById($parent->getId(), throwException: $throwException);
                $observed['exception'] = $caught;
                $observed['child_exception'] = $firstConnection->cancellation;
                $observed['child_running'] = Coroutine::exists($firstConnection->coroutineId);
                $observed['parent_running'] = Coroutine::exists($parent->getId());
                $observed['managed'] = $pool->getManagedCount();
                $observed['idle'] = $pool->getIdleCount();
                $observed['later_started'] = $laterConnection->coroutineId;
            } finally {
                if (Coroutine::exists($parent->getId())) {
                    Coroutine::cancelById($parent->getId(), throwException: true);
                    FrameworkCoroutine::join([$parent->getId()], 1.0);
                }

                $pool->close();
            }
        });

        $this->assertTrue($observed['started']);
        $this->assertTrue($observed['canceled']);
        $this->assertInstanceOf(CanceledException::class, $observed['exception']);
        $this->assertInstanceOf(CanceledException::class, $observed['child_exception']);
        $this->assertFalse($observed['child_running']);
        $this->assertFalse($observed['parent_running']);
        $this->assertSame(1, $observed['managed']);
        $this->assertSame(1, $observed['idle']);
        $this->assertNull($observed['later_started']);
    }

    public static function nativeCancellationModes(): array
    {
        return [[false], [true]];
    }

    /**
     * @param array<string, mixed> $poolOptions
     */
    protected function createPool(array $poolOptions = [], string $poolClass = InspectableHeartbeatDatabasePool::class): InspectableHeartbeatDatabasePool
    {
        $this->app->make('config')->set('database.connections.heartbeat_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 2,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => null,
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

        if ($lifetimeExpiresAt->getValue($connection) !== null) {
            $lifetimeExpiresAt->setValue($connection, hrtime(true) / 1e9 - 1.0);
        }
    }
}

class InspectableHeartbeatDatabasePool extends DatabasePool
{
    public function runHeartbeatForTest(): void
    {
        $this->heartbeat();
    }

    public function heartbeatTimerCount(): int
    {
        $timer = (new ReflectionProperty(DatabasePool::class, 'heartbeatTimer'))->getValue($this);

        return $timer === null ? 0 : count((new ClassInvoker($timer))->coroutines);
    }
}

class CancellableDisposalDatabasePool extends InspectableHeartbeatDatabasePool
{
    protected function createConnection(): Connection
    {
        return new CancellableDisposalPooledConnection($this->container, $this, $this->config);
    }
}

class CancellableDisposalPooledConnection extends PooledConnection
{
    public ?Throwable $pingFailure = null;

    public ?Throwable $closeFailure = null;

    public bool $healthy = true;

    public int $pingCount = 0;

    public int $closeCount = 0;

    public function ping(float $timeout): bool
    {
        ++$this->pingCount;

        if ($this->pingFailure !== null) {
            throw $this->pingFailure;
        }

        return $this->healthy;
    }

    public function close(): bool
    {
        ++$this->closeCount;
        parent::close();

        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }

        return true;
    }
}

class FailingHeartbeatDatabasePool extends InspectableHeartbeatDatabasePool
{
    protected function createConnection(): Connection
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

class LifetimeExpiredPingTrackingDatabasePool extends InspectableHeartbeatDatabasePool
{
    protected function createConnection(): Connection
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

class ClosingHeartbeatDatabasePool extends InspectableHeartbeatDatabasePool
{
    protected function createConnection(): Connection
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

class OpenTransactionFailingHeartbeatDatabasePool extends InspectableHeartbeatDatabasePool
{
    protected function createConnection(): Connection
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

class CancellableHeartbeatConnection extends SQLiteConnection
{
    public Channel $pingStarted;

    public Channel $blocker;

    public ?CanceledException $cancellation = null;

    public ?int $coroutineId = null;

    public function ping(): bool
    {
        $this->coroutineId = Coroutine::id();
        $this->pingStarted->push(true);

        try {
            $this->blocker->pop();
        } catch (CanceledException $exception) {
            $this->cancellation = $exception;

            throw $exception;
        }

        return true;
    }
}

class SlowHeartbeatConnection extends SQLiteConnection
{
    public static ?int $coroutineId = null;

    public function ping(): bool
    {
        self::$coroutineId = Coroutine::id();

        usleep(500000);

        return false;
    }
}
