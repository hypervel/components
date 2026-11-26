<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use BadMethodCallException;
use ErrorException;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\ConnectionPool\Exceptions\ConnectionException;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Container\Container;
use Hypervel\Contracts\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Events\Dispatcher;
use Hypervel\Redis\Exceptions\InvalidRedisOptionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\Redis\Fixtures\FakeRedisClient;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LogLevel;
use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Process\Process;
use Throwable;
use TypeError;

class RedisConnectionTest extends TestCase
{
    use VerifiesDoubles;

    public function testShouldTransform(): void
    {
        $connection = $this->mockRedisConnection();

        $this->assertFalse($connection->getShouldTransform());

        $connection->shouldTransform(true);

        $this->assertTrue($connection->getShouldTransform());
    }

    public function testRelease(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release');

        $connection = $this->mockRedisConnection(pool: $pool);
        $redis = $this->mockIdleRedisClient();
        $connection->setActiveConnection($redis);
        $connection->shouldTransform(true);

        $connection->release();

        $this->assertFalse($connection->getShouldTransform());
    }

    public function testSuccessfulAtomicSelectTracksTheAppliedDatabase(): void
    {
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('select')->with(2)->returns(true);
        $connection = $this->mockRedisConnection();
        $connection->setActiveConnection($redis);

        $this->assertTrue($connection->__call('SELECT', [2]));
        $this->assertSame(
            2,
            (new ReflectionProperty(RedisConnection::class, 'database'))->getValue($connection),
        );
    }

    public function testFailedAndQueuedSelectResultsAreNotTrackedAsApplied(): void
    {
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('select')->with(2)->returns(false);
        $redis->expects('select')->with(3)->returns($redis);
        $connection = $this->mockRedisConnection();
        $connection->setActiveConnection($redis);
        $database = new ReflectionProperty(RedisConnection::class, 'database');

        $this->assertFalse($connection->__call('select', [2]));
        $this->assertNull($database->getValue($connection));
        $this->assertSame($redis, $connection->__call('select', [3]));
        $this->assertNull($database->getValue($connection));
    }

    public function testReleaseResetsDatabaseToConfiguredDefault(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release');

        $redis = Double::for(Redis::class)->strict();
        $redis->expects('select')->times(2)->with(1)->returns(true);
        $redis->expects('select')->with(2)->returns(true);
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->returns(true);
        $redis->expects('getDBNum')->returns(2);

        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 1]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->__call('select', [2]);
        $connection->release();
    }

    public function testReleaseRestoresTheNativeDatabaseWithoutTrackedSelection(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->returns(true);
        $redis->expects('getDBNum')->returns(2);
        $redis->expects('select')->with(0)->returns(true);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();

        $this->assertNull(
            (new ReflectionProperty(RedisConnection::class, 'database'))->getValue($connection),
        );
    }

    public function testReleaseInvalidatesDisconnectedStandaloneConnectionWithoutInspectingItsDatabase(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->returns(false);
        $redis->expects('getDBNum')->never();
        $redis->expects('select')->never();
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleaseDiscardsAConnectionInMultiMode(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::MULTI);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testReleaseDiscardsAnInvalidatedTransactionWithoutReportingAbandonment(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')->never();
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $pool->expects('release')->never();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::MULTI);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);
        $connection->invalidate();

        $connection->release();
    }

    public function testReleaseDiscardsAConnectionInPipelineMode(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::PIPELINE);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testReleaseDiscardsAConnectionWithAnActiveWatch(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $this->assertTrue($connection->__call('watch', ['key']));

        $connection->release();
    }

    #[DataProvider('watchTerminalCommandProvider')]
    public function testSuccessfulTerminalCommandsClearTrackedWatchState(
        string $command,
        mixed $result,
    ): void {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = $this->mockIdleRedisClient();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects(strtolower($command))->returns($result);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('WATCH', ['key']);
        $this->assertSame($result, $connection->__call($command, []));
        $connection->release();
    }

    public static function watchTerminalCommandProvider(): array
    {
        return [
            'unwatch' => ['UNWATCH', true],
            'exec conflict' => ['EXEC', false],
        ];
    }

    public function testFailedUnwatchRetainsTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('unwatch')->returns(false);
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $this->assertFalse($connection->__call('unwatch', []));
        $connection->release();
    }

    public function testNativeDiscardClearsTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = $this->mockIdleRedisClient();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('discard')->returns(true);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $this->assertTrue($connection->discardTransaction());
        $connection->release();
    }

    public function testClearWatchStateAllowsOrdinaryRelease(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = $this->mockIdleRedisClient();
        $redis->expects('watch')->with('key')->returns(true);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $connection->clearWatchState();
        $connection->release();
    }

    public function testReconnectBeginsWithNoTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->times(2)->returns(false);
        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->__call('watch', ['key']);
        $connection->reconnect();
        $connection->release();
    }

    public function testReconnectPreservesExactCancellation(): void
    {
        $cancellation = new CanceledException('reconnect canceled');
        $redis = Double::for(Redis::class)->strict();
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $redis, $cancellation) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $redis,
                private CanceledException $cancellation,
            ) {
                RedisConnection::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->redis;
            }

            protected function setOptions(Redis|RedisCluster $redis): void
            {
                throw $this->cancellation;
            }
        };

        try {
            $connection->reconnect();
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCloseClearsTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('close')->returns(true);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $connection->close();
        $connection->release();
    }

    public function testReleaseChecksNativeModeBeforeRestoringDatabase(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('select')
            ->with(2)
            ->ordered()
            ->returns(true);
        $redis->expects('getMode')
            ->ordered()
            ->returns(Redis::ATOMIC);
        $redis->expects('isConnected')
            ->ordered()
            ->returns(true);
        $redis->expects('getDBNum')
            ->ordered()
            ->returns(2);
        $redis->expects('select')
            ->with(0)
            ->ordered()
            ->returns(true);
        $connection = $this->mockRedisConnection(pool: $pool, options: ['database' => 0]);
        $connection->setActiveConnection($redis);
        $connection->__call('select', [2]);

        $connection->release();
    }

    public function testModeDetectionFailureInvalidatesAndReleasesConnection(): void
    {
        $container = $this->getContainer();
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->throws(new RuntimeException('Mode failed.'));
        $connection = new class($container, $pool, $this->standaloneConfig()) extends PhpRedisConnectionStub {
            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }

            public function recoverForTest(): void
            {
                $this->markValid();
            }
        };
        $connection->setActiveConnection($redis);

        // An observer can recover the connection, as getActiveConnection() does by reconnecting.
        $dispatcher->listen(ConnectionReleasing::class, static fn () => $connection->recoverForTest());

        $connection->release();

        $this->assertTrue($connection->isInvalidForTest());
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'phpredis failure carrying a cancellation')]
    public function testReleasePreservesCancellationWithoutDispatchingReleaseObservers(bool $carried): void
    {
        $cancellation = $carried
            ? new RedisException('mode canceled', previous: new CanceledException)
            : new CanceledException('mode canceled');
        $releaseObserved = false;
        $container = $this->getContainer();
        $dispatcher = new Dispatcher($container);
        $dispatcher->listen(ConnectionReleasing::class, function () use (&$releaseObserved): void {
            $releaseObserved = true;
        });
        $container->instance('events', $dispatcher);
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')->never();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $pool->expects('discard')->never();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->throws($cancellation);
        $redis->expects('close')->never();
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the cancellation to escape.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($releaseObserved);
        $this->assertTrue($connection->isInvalidForTest());
        $this->assertNull(
            (new ReflectionProperty(RedisConnection::class, 'database'))->getValue($connection),
        );
    }

    public function testReleaseThrowsAnObserverReportingFailureAfterReturningTheConnection(): void
    {
        $reportingFailure = new RuntimeException('Reporting the release observer failure failed.');
        $container = $this->getContainer();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('error')->throws($reportingFailure);
        $container->instance(StdoutLoggerInterface::class, $logger);
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['events' => [ConnectionReleasing::class]]));
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->allows('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->returns(true);
        $redis->expects('getDBNum')->returns(0);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);
        $dispatcher->listen(ConnectionReleasing::class, static function () use ($connection): never {
            $connection->shouldTransform(true);

            throw new RuntimeException('Release observer failed.');
        });

        try {
            $connection->release();
            $this->fail('Expected the reporting failure to escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
        }

        $this->assertFalse($connection->getShouldTransform());
    }

    public function testReleasePreservesOperationCancellationOverPoolCleanupCancellation(): void
    {
        $cancellation = new CanceledException('mode canceled');
        $cleanupCancellation = new CanceledException('pool release canceled');
        $pool = $this->getMockedPool();
        $pool->expects('release')->throws($cleanupCancellation);
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->throws($cancellation);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCancellationFromQueueingWarningInvalidatesAndReleasesWithoutDiscarding(bool $carried): void
    {
        $cancellation = $carried
            ? new RuntimeException('logging failed', previous: new CanceledException('logging canceled'))
            : new CanceledException('logging canceled');
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')
            ->with(
                LogLevel::CRITICAL,
                'Discarding Redis connection left in MULTI or PIPELINE mode.'
            )
            ->throws($cancellation);
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $pool->expects('discard')->never();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('close')->never();
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);
        $caught = null;

        try {
            $connection->release();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleaseNormalizesWrappedCancellationFromNativeClose(): void
    {
        $nativeFailure = new RedisException('close canceled', previous: new CanceledException);
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')->never();
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $pool->expects('discard')->never();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('isConnected')->throws(new RuntimeException('validation failed'));
        $redis->expects('close')->throws($nativeFailure);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);
        $exception = null;

        try {
            $connection->release();
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame('Closing the Redis connection was canceled.', $exception->getMessage());
        $this->assertSame($nativeFailure, $exception->getPrevious());
        $this->assertNull($connection->client());
    }

    #[DataProvider('databaseRestoreFailureProvider')]
    public function testDatabaseRestoreFailureClosesTheNativeGenerationBeforeReconnect(string $failureMode): void
    {
        $pool = $this->getMockedPool();
        $pool->allows('getName')->returns('default');
        $pool->expects('release')->with(Argument::type(RedisConnection::class));
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')->with(
            LogLevel::CRITICAL,
            Argument::satisfies(static fn (string $message): bool => str_starts_with($message, 'Release connection failed, caused by ')),
        );
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $oldRedis = Double::for(Redis::class)->strict();
        $newRedis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->returns(true);
        $oldRedis->expects('getMode')->returns(Redis::ATOMIC);
        $oldRedis->expects('isConnected')->returns(true);
        $oldRedis->expects('getDBNum')->returns(2);
        $restore = $oldRedis->expects('select')->with(0);

        if ($failureMode === 'false') {
            $restore->returns(false);
        } else {
            $restore->throws(new RuntimeException('Select failed.'));
        }

        $oldRedis->expects('close')->returns(true);
        $newRedis->expects('select')->never();
        $connection = new class($container, $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }

            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }
        };
        $connection->__call('select', [2]);

        $connection->release();

        $this->assertTrue($connection->isInvalidForTest());
        $this->assertNull($connection->client());
        $this->assertSame($connection, $connection->getActiveConnection());
        $this->assertSame($newRedis, $connection->client());
        $this->assertFalse($connection->isInvalidForTest());
    }

    public static function databaseRestoreFailureProvider(): array
    {
        return [
            'false result' => ['false'],
            'exception' => ['exception'],
        ];
    }

    public function testReportingFailureCannotPreventQueueingModeDiscard(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')
            ->with(
                LogLevel::CRITICAL,
                'Discarding Redis connection left in MULTI or PIPELINE mode.'
            )
            ->throws(new RuntimeException('Logging failed.'));
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::MULTI);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testDiscardOwnershipFailurePropagates(): void
    {
        $exception = new RuntimeException('Discard ownership failed.');
        $pool = $this->getMockedPool();
        $pool->expects('discard')->throws($exception);
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::MULTI);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the discard ownership failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testReconnectUsesCurrentDatabaseWhenSet(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('select')->times(2)->with(2)->returns(true);
        $redis->expects('isConnected')->returns(true);
        $redis->expects('getDBNum')->returns(2);

        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };

        $connection->__call('select', [2]);
        $connection->reconnect();
    }

    public function testReconnectCarriesTheConnectedNativeClientsActualDatabaseAcrossAReplacement(): void
    {
        $pool = $this->getMockedPool();
        $oldRedis = Double::for(Redis::class)->strict();
        $newRedis = Double::for(Redis::class)->strict();
        $secondNewRedis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $this->expectDefaultConnectionOptions($secondNewRedis);
        $oldRedis->expects('isConnected')->returns(true);
        $oldRedis->expects('getDBNum')->returns(2);
        $newRedis->expects('select')->with(2)->returns(true);
        $newRedis->expects('isConnected')->returns(false);
        $newRedis->expects('getDBNum')->never();
        $secondNewRedis->expects('select')->with(2)->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis, $secondNewRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }
        };

        $connection->reconnect();
        $connection->reconnect();

        $this->assertSame($secondNewRedis, $connection->client());
    }

    public function testReconnectRejectsARefusedDatabaseBeforePublishingTheNewClient(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('getName')->returns('default');
        $oldRedis = Double::for(Redis::class)->strict();
        $newRedis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->returns(true);
        $oldRedis->expects('isConnected')->never();
        $oldRedis->expects('getDBNum')->never();
        $newRedis->expects('select')->with(2)->returns(false);
        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 2]), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }

            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }
        };
        $connection->invalidate();
        $exception = null;

        try {
            $connection->reconnect();
        } catch (ConnectionException $exception) {
        }

        $this->assertInstanceOf(ConnectionException::class, $exception);
        $this->assertSame('Failed to select Redis database [2] on connection [default].', $exception->getMessage());
        $this->assertSame($oldRedis, $connection->client());
        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReconnectToDatabaseZeroDoesNotIssueSelect(): void
    {
        $redis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($redis);
        $redis->expects('select')->never();
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $redis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->redis;
            }
        };

        $this->assertSame($redis, $connection->client());
    }

    public function testReconnectDoesNotInspectADisconnectedClientAndUsesTrackedSelection(): void
    {
        $pool = $this->getMockedPool();
        $oldRedis = Double::for(Redis::class)->strict();
        $newRedis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->returns(true);
        $oldRedis->expects('isConnected')->returns(false);
        $oldRedis->expects('getDBNum')->never();
        $newRedis->expects('select')->with(2)->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }
        };

        $this->assertTrue($connection->__call('select', [2]));
        $connection->reconnect();

        $this->assertSame($newRedis, $connection->client());
    }

    public function testReconnectDoesNotInspectAnInvalidClientAndUsesTrackedSelection(): void
    {
        $pool = $this->getMockedPool();
        $oldRedis = Double::for(Redis::class)->strict();
        $newRedis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->returns(true);
        $oldRedis->expects('isConnected')->never();
        $oldRedis->expects('getDBNum')->never();
        $newRedis->expects('select')->with(2)->returns(true);
        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }
        };

        $this->assertTrue($connection->__call('select', [2]));
        $connection->invalidate();
        $connection->reconnect();

        $this->assertSame($newRedis, $connection->client());
    }

    public function testSentinelResolvedMasterUsesStandaloneDataConnectionSettings(): void
    {
        $sentinelFactory = Double::for(RedisSentinelFactory::class)->strict();
        $sentinelFactory->expects('resolveMaster')->returns(['127.0.0.1', 6380]);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->returns($sentinelFactory);
        $container->allows('bound')->with('events')->returns(false);
        $container->allows('has')->returns(false);
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')->with(Redis::OPT_READ_TIMEOUT, 2.5)->returns(true);
        $this->expectDefaultConnectionOptions($redis);
        $connection = new class($container->instance(), $this->getMockedPool(), $this->sentinelConfig(['timeout' => 1.5, 'read_timeout' => 2.5, 'context' => ['stream' => ['tcp_nodelay' => true]]]), $redis) extends PhpRedisConnection {
            private array $createdConfig = [];

            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            public function getCreatedConfig(): array
            {
                return $this->createdConfig;
            }

            protected function createRedis(array $config): Redis
            {
                $this->createdConfig = $config;

                return $this->fakeRedis;
            }
        };

        $this->assertSame(1.5, $connection->getCreatedConfig()['timeout']);
        $this->assertSame(2.5, $connection->getCreatedConfig()['read_timeout']);
        $this->assertSame(
            ['stream' => ['tcp_nodelay' => true]],
            $connection->getCreatedConfig()['context'],
        );
    }

    public function testSentinelResolutionPreservesExactCancellation(): void
    {
        $cancellation = new CanceledException('sentinel canceled');
        $sentinelFactory = Double::for(RedisSentinelFactory::class)->strict();
        $sentinelFactory->expects('resolveMaster')->throws($cancellation);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->returns($sentinelFactory);
        $container->allows('bound')->with('events')->returns(false);
        $container->allows('has')->returns(false);

        try {
            new PhpRedisConnection($container->instance(), $this->getMockedPool(), $this->sentinelConfig());
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testSentinelResolutionPreservesTheUnderlyingFailure(): void
    {
        $failure = new RuntimeException('sentinel unavailable');
        $sentinelFactory = Double::for(RedisSentinelFactory::class)->strict();
        $sentinelFactory->expects('resolveMaster')->throws($failure);
        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->returns($sentinelFactory);
        $container->allows('bound')->with('events')->returns(false);
        $container->allows('has')->returns(false);

        try {
            new PhpRedisConnection($container->instance(), $this->getMockedPool(), $this->sentinelConfig());
            $this->fail('Expected Sentinel resolution to fail.');
        } catch (ConnectionException $exception) {
            $this->assertSame('Connection reconnect failed: sentinel unavailable', $exception->getMessage());
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testConnectionConfigIsStoredWithoutAHiddenSchema(): void
    {
        $config = ['host' => 'redis'];
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
            $config,
        );

        $this->assertSame($config, $connection->getConfigForTest());
    }

    public function testNormalizeContextWrapsFlatArrayInStream(): void
    {
        $result = $this->callNormalizeContext([
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]);

        $this->assertSame([
            'stream' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ], $result);
    }

    public function testNormalizeContextConvertsSslKeyToStream(): void
    {
        $result = $this->callNormalizeContext([
            'ssl' => [
                'verify_peer' => false,
                'cafile' => '/path/to/ca.pem',
            ],
        ]);

        $this->assertSame([
            'stream' => [
                'verify_peer' => false,
                'cafile' => '/path/to/ca.pem',
            ],
        ], $result);
    }

    public function testNormalizeContextPassesThroughStreamKey(): void
    {
        $context = [
            'stream' => [
                'verify_peer' => false,
            ],
        ];

        $result = $this->callNormalizeContext($context);

        $this->assertSame($context, $result);
    }

    public function testNormalizeContextSslKeyTakesPrecedenceOverFlatKeys(): void
    {
        $result = $this->callNormalizeContext([
            'verify_peer' => true,
            'ssl' => [
                'verify_peer' => false,
            ],
        ]);

        $this->assertSame([
            'stream' => [
                'verify_peer' => false,
            ],
        ], $result);
    }

    public function testEmptyContextKeepsStandaloneConnectionPlaintext(): void
    {
        $server = new RespServer;
        $bytes = null;
        $connection = null;
        $server->start(static function ($client) use (&$bytes): void {
            $bytes = stream_get_contents($client, 2);
            fwrite($client, "+PONG\r\n");
        });
        [$host, $port] = $server->hostAndPort();

        try {
            $connection = new PhpRedisConnection(
                $this->getContainer(),
                $this->getMockedPool(),
                [
                    ...$this->standaloneConfig(),
                    'host' => $host,
                    'port' => $port,
                    'timeout' => 1.0,
                    'context' => [],
                ],
            );
            $connection->ping();
        } finally {
            $connection?->close();
            $server->wait();
        }

        $this->assertSame('*1', $bytes);
    }

    public function testNonEmptyContextEnablesTlsForStandaloneConnection(): void
    {
        $server = new RespServer('tls://127.0.0.1:0', [
            'ssl' => [
                'local_cert' => __DIR__ . '/Fixtures/Tls/server.crt',
                'local_pk' => __DIR__ . '/Fixtures/Tls/server.key',
                'allow_self_signed' => true,
            ],
        ]);
        $bytes = null;
        $connection = null;
        $server->start(static function ($client) use (&$bytes): void {
            $bytes = stream_get_contents($client, 2);
            fwrite($client, "+PONG\r\n");
        });
        [$host, $port] = $server->hostAndPort();

        try {
            $connection = new PhpRedisConnection(
                $this->getContainer(),
                $this->getMockedPool(),
                [
                    ...$this->standaloneConfig(),
                    'host' => $host,
                    'port' => $port,
                    'timeout' => 1.0,
                    'context' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ],
            );
            $connection->ping();
        } finally {
            $connection?->close();
            $server->wait();
        }

        $this->assertSame('*1', $bytes);
    }

    public function testTlsConnectCancellationEscapesFromPhpRedisConnection(): void
    {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        $this->assertIsString($autoload);
        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/CancelTlsConnection.php',
            $autoload,
        ]);
        $process->setTimeout(10.0);
        $process->mustRun();

        $this->assertSame("canceled\n", $process->getOutput());
    }

    public function testClusterReconnectFailureThrowsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageIsOrContains('Connection reconnect failed');

        new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['cluster' => ['enabled' => true, 'seeds' => []]])) extends PhpRedisClusterConnection {
        };
    }

    public function testQueueingModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('set')->with('key', 'value', 600)->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testPipelineModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::PIPELINE);
        $redis->expects('set')->with('key', 'value', 600)->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesSetArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('set')
            ->with('key', 'value', ['NX', 'EX' => 600])
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 'EX', 600, 'NX']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModePreservesNativeSetOptionsAndRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('set')
            ->with('key', 'value', ['GET', 'EX' => 600])
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', ['GET', 'EX' => 600]]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesHmsetArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('hmset', ['hash', 'field1', 'value1', 'field2', 'value2']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesLremArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('lRem')
            ->with('list', 'value', 2)
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('lrem', ['list', 2, 'value']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZaddArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('zAdd')
            ->with('sortedset', ['NX', 'CH'], 1.0, 'member1', 2.0, 'member2')
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zadd', ['sortedset', 'NX', 'CH', 1.0, 'member1', 2.0, 'member2']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZrangebyscoreArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('zRangeByScore')
            ->with('sortedset', '1', '5', ['limit' => [0, 10]])
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zrangebyscore', ['sortedset', '1', '5', ['limit' => ['offset' => 0, 'count' => 10]]]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZinterstoreArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('zinterstore')
            ->with('output', ['set1', 'set2'], [1, 2], 'max')
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2'], ['weights' => [1, 2], 'aggregate' => 'max']]);

        $this->assertSame($redis, $result);
    }

    #[DataProvider('numericCommandArguments')]
    public function testNumericArgumentsPreserveFailuresAndQueuedReturns(string $method, array $arguments, array $expected): void
    {
        foreach ([Redis::ATOMIC, Redis::MULTI, Redis::PIPELINE] as $mode) {
            $connection = $this->mockRedisConnection(transform: true);
            $redis = Double::for(Redis::class)->strict();
            $redis->expects('getMode')->returns($mode);
            $result = $mode === Redis::ATOMIC ? false : $redis;
            $redis->expects($method)->with(...$expected)->returns($result);
            $connection->setActiveConnection($redis);

            $this->assertSame($result, $connection->__call($method, $arguments));
        }
    }

    /**
     * Provide numeric bounds and optional stream arguments.
     *
     * @return array<string, array{string, array<mixed>, array<mixed>}>
     */
    public static function numericCommandArguments(): array
    {
        return [
            'score count' => ['zCount', ['set', 1, 2.5], ['set', '1', '2.5']],
            'score removal' => ['zRemRangeByScore', ['set', 1.5, '+inf'], ['set', '1.5', '+inf']],
            'stream append' => ['xAdd', ['stream', 1.0, ['field' => 'value']], ['stream', '1', ['field' => 'value']]],
            'stream claim' => ['xAutoClaim', ['stream', 'group', 'consumer', 0, 0, 2, true], ['stream', 'group', 'consumer', 0, '0', 2, true]],
            'stream group' => ['xGroup', ['CREATE', 'stream', 'group', 0], ['CREATE', 'stream', 'group', '0']],
            'group help' => ['xGroup', ['HELP'], ['HELP']],
            'pending range' => ['xPending', ['stream', 'group', 0, 2, 10], ['stream', 'group', '0', '2', 10]],
            'pending summary' => ['xPending', ['stream', 'group'], ['stream', 'group']],
            'pending null bounds' => ['xPending', ['stream', 'group', null, null], ['stream', 'group', null, null]],
            'stream range' => ['xRange', ['stream', 0, 2, 1], ['stream', '0', '2', 1]],
            'reverse stream range' => ['xRevRange', ['stream', 2, 0], ['stream', '2', '0']],
            'stream trim' => ['xTrim', ['stream', 1, true, true, 10], ['stream', '1', true, true, 10]],
        ];
    }

    public function testClusterScoreRemovalPreservesNamedBoundsAndQueuedReturns(): void
    {
        foreach ([Redis::ATOMIC, Redis::MULTI] as $mode) {
            $connection = new PhpRedisClusterConnectionStub;
            $connection->shouldTransform();
            $redis = Double::for(RedisCluster::class)->strict();
            $redis->expects('getMode')->returns($mode);
            $result = $mode === Redis::ATOMIC ? false : $redis;
            $redis->expects('zRemRangeByScore')->with('set', '1.5', '(2')->returns($result);
            $connection->setActiveConnection($redis);

            $this->assertSame($result, $connection->zRemRangeByScore('set', min: 1.5, max: '(2'));
        }
    }

    public function testPrepareEvalShapesArgumentsForQueueingMode(): void
    {
        $connection = new class extends PhpRedisConnectionStub {
            public function prepareEvalForTest(mixed ...$arguments): array
            {
                return $this->prepareEval(...$arguments);
            }
        };

        $this->assertSame(
            ['eval', ['return redis.call("GET", KEYS[1])', ['mykey', 'myarg'], 1]],
            $connection->prepareEvalForTest('return redis.call("GET", KEYS[1])', 1, 'mykey', 'myarg'),
        );
    }

    public function testPrepareEvalshaFallsBackToEvalForQueueingMode(): void
    {
        $connection = new class extends PhpRedisConnectionStub {
            public function prepareEvalshaForTest(mixed ...$arguments): array
            {
                return $this->prepareEvalsha(...$arguments);
            }
        };

        $this->assertSame(
            ['eval', ['return redis.call("GET", KEYS[1])', ['mykey'], 1]],
            $connection->prepareEvalshaForTest('return redis.call("GET", KEYS[1])', 1, 'mykey'),
        );
    }

    public function testQueueingModeReshapesExecuteRawArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::MULTI);
        $redis->expects('rawCommand')
            ->with('CUSTOM', 'arg1', 'arg2')
            ->returns($redis);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('executeRaw', [['CUSTOM', 'arg1', 'arg2']]);

        $this->assertSame($redis, $result);
    }

    public function testTransformDisabledSetUsesNativeSignatureWithoutInspectingMode(): void
    {
        $connection = $this->mockRedisConnection(transform: false);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->never();
        $redis->expects('set')->with('key', 'value', 600)->returns(true);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertTrue($result);
    }

    public function testTypeErrorsAreNotRetried(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = Double::for(Redis::class)->strict();

        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('set')
            ->with('key', 'value', 600)
            ->throws(new TypeError('Invalid native Redis argument.'));
        $connection->setActiveConnection($redis);

        $this->expectException(TypeError::class);

        $connection->__call('set', ['key', 'value', 600]);
    }

    public function testMacrosCanBeRegisteredInvokedAndFlushed(): void
    {
        RedisConnection::macro('greeting', fn (string $name) => "Hello {$name}");
        $connection = $this->mockRedisConnection();

        $this->assertTrue(RedisConnection::hasMacro('greeting'));
        $this->assertSame('Hello Taylor', $connection->__call('greeting', ['Taylor']));

        RedisConnection::flushMacros();

        $this->assertFalse(RedisConnection::hasMacro('greeting'));
    }

    public function testMixinRegistersConnectionMacros(): void
    {
        RedisConnection::mixin(new class {
            protected function greeting(): callable
            {
                return fn (string $name) => "Hello {$name}";
            }
        });

        $this->assertSame(
            'Hello Taylor',
            $this->mockRedisConnection()->__call('greeting', ['Taylor']),
        );
    }

    public function testMacroLookupPreservesExactNamesAndMayShadowNativeCommands(): void
    {
        RedisConnection::macro('GET', fn () => 'exact');
        RedisConnection::macro('reset', fn () => 'shadowed');
        $connection = $this->mockRedisConnection();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('get')->with('key')->returns('native');
        $redis->expects('reset')->never();
        $connection->setActiveConnection($redis);

        $this->assertSame('exact', $connection->__call('GET', []));
        $this->assertSame('native', $connection->__call('get', ['key']));
        $this->assertSame('shadowed', $connection->__call('reset', []));
    }

    public function testRedisExceptionInsideMacroUsesConnectionDispositionRule(): void
    {
        $exception = new RedisException('Connection lost.');
        RedisConnection::macro('failing', function () {
            return $this->connection->get('key');
        });
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
        );
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('get')->with('key')->throws($exception);
        $redis->expects('getLastError')->returns(null);
        $connection->setActiveConnection($redis);

        try {
            $connection->__call('failing', []);
            $this->fail('Expected the macro command failure to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testTransportFailureInvalidatesWithoutReplayingCommand(): void
    {
        $exception = new RedisException('Connection lost.');
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('get')
            ->with('foo')
            ->throws($exception);
        $redis->expects('getLastError')->returns(null);
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
        );
        $connection->setActiveConnection($redis);

        try {
            $connection->__call('get', ['foo']);
            $this->fail('Expected the transport failure to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testClusterTransportFailureInvalidatesWithoutReplayingCommand(): void
    {
        $exception = new RedisClusterException('Error processing EXEC across the cluster');
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('exec')
            ->throws($exception);
        $redis->expects('getLastError')->returns("CROSSSLOT Keys in request don't hash to the same slot");
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
        );
        $connection->setActiveConnection($redis);

        try {
            $connection->__call('exec', []);
            $this->fail('Expected the Cluster transport failure to propagate.');
        } catch (RedisClusterException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    // REMOVED: Automatic command retries and reopening pipelines/transactions can replay committed commands.
    #[DataProvider('connectionFailureProvider')]
    public function testConnectionRebuildsItsClientOnNextAcquisitionWithoutReplayingCommand(
        RedisException|RedisClusterException|ErrorException $exception,
        string $command,
        array $arguments,
        bool $synchronized,
    ): void {
        $failedClient = Double::for(Redis::class)->strict();
        $healthyClient = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($failedClient);
        $this->expectDefaultConnectionOptions($healthyClient);
        $failedClient->expects($command)->with(...$arguments)->throws($exception);
        if (! $exception instanceof ErrorException) {
            $failedClient->expects('getLastError')->returns($synchronized ? $exception->getMessage() : null);
        }
        $failedClient->expects('isConnected')->never();
        $healthyClient->expects('get')->with('foo')->returns('bar');

        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), [$failedClient, $healthyClient]) extends PhpRedisConnection {
            /**
             * Create a connection with replacement native clients.
             *
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private array $clients,
            ) {
                parent::__construct($container, $pool, $config);
            }

            /**
             * Return the next native client.
             */
            protected function createRedis(array $config): Redis
            {
                return array_shift($this->clients);
            }
        };

        try {
            $connection->__call($command, $arguments);
            $this->fail('Expected the command failure to propagate.');
        } catch (RedisException|RedisClusterException|ErrorException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse($connection->check());
        $this->assertSame($failedClient, $connection->client());
        $this->assertSame($connection, $connection->getActiveConnection());
        $this->assertSame($healthyClient, $connection->client());
        $this->assertTrue($connection->check());
        $this->assertSame('bar', $connection->__call('get', ['foo']));
    }

    /**
     * Provide failover and transport errors across command types.
     */
    public static function connectionFailureProvider(): array
    {
        return [
            'read-only replica' => [new RedisException('READONLY replica is read-only'), 'set', ['foo', 'bar'], true],
            'disconnected replica' => [new RedisException('MASTERDOWN link is down'), 'get', ['foo'], true],
            'read failure' => [new RedisException('Connection lost'), 'get', ['foo'], false],
            'warning-shaped reset' => [new ErrorException('Redis::get(): SSL: Connection reset by peer'), 'get', ['foo'], false],
            'broken pipe while reading' => [new ErrorException('Redis::lLen(): SSL: Broken pipe'), 'llen', ['foo'], false],
            'broken pipe during a non-idempotent write' => [new ErrorException('Redis::rPush(): SSL: Broken pipe'), 'rpush', ['foo', 'bar'], false],
            'stream write failure' => [new ErrorException('Redis::get(): Send of 30 bytes failed with errno=32 Broken pipe'), 'get', ['foo'], false],
            'TLS protocol failure' => [new ErrorException('Redis::get(): SSL operation failed with code 1. OpenSSL Error messages: bad length'), 'get', ['foo'], false],
            'transaction opening' => [new RedisException('Connection lost'), 'multi', [], false],
            'non-idempotent write' => [new RedisException('Connection lost'), 'incr', ['foo'], false],
            'write with options' => [new RedisException('Connection lost'), 'set', ['foo', 'bar', ['ex' => 60]], false],
            'cluster response error' => [new RedisClusterException('Error processing response from Redis node!'), 'get', ['foo'], false],
            'no reachable cluster node' => [new RedisClusterException("Can't communicate with any node in the cluster"), 'get', ['foo'], false],
        ];
    }

    public function testConnectionDoesNotRetryUnrelatedWarnings(): void
    {
        $exception = new ErrorException('Redis::get(): Undefined variable');
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('get')->with('foo')->throws($exception);
        $redis->expects('getLastError')->never();
        $connection = new PhpRedisConnectionStub($this->getContainer(), $this->getMockedPool());
        $connection->setActiveConnection($redis);

        try {
            $connection->__call('get', ['foo']);
            $this->fail('Expected the unrelated warning to propagate.');
        } catch (ErrorException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse($connection->isInvalidForTest());
        $this->assertSame($redis, $connection->client());
    }

    #[DataProvider('synchronizedServerErrorDispositionProvider')]
    public function testSynchronizedServerErrorDispositionDoesNotReplayCommand(
        string $message,
        bool $invalid,
    ): void {
        $exception = new RedisException($message);
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('set')->with('key', 'value')->throws($exception);
        $redis->expects('getLastError')->returns($message);
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
        );
        $connection->setActiveConnection($redis);

        try {
            $connection->__call('set', ['key', 'value']);
            $this->fail('Expected the Redis server error to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertSame($invalid, $connection->isInvalidForTest());
    }

    /**
     * Provide server errors that invalidate or retain a synchronized connection.
     */
    public static function synchronizedServerErrorDispositionProvider(): array
    {
        return [
            'READONLY' => ['READONLY replica is read-only', true],
            'MASTERDOWN' => ['MASTERDOWN link is down', true],
            'LOADING' => ['LOADING data is loading', false],
            'OOM' => ['OOM command not allowed', false],
            'MISCONF' => ['MISCONF persistence error', false],
            'CROSSSLOT' => ["CROSSSLOT Keys in request don't hash to the same slot", false],
            'non-exact READONLY prefix' => ['READONLY_STATE custom error', false],
        ];
    }

    public function testLogWritesToStdoutLogger(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('log')
            ->with(LogLevel::ERROR, 'unit');

        $container = Double::for(ContainerContract::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);
        $container->allows('has')->with(StdoutLoggerInterface::class)->returns(true);
        $container->allows('make')->with(StdoutLoggerInterface::class)->returns($logger);

        $redis->allows('setOption')->returns(true);

        $connection = new class($container->instance(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }

            public function callLog(string $message, string $level): void
            {
                $this->log($message, $level);
            }
        };

        $connection->callLog('unit', LogLevel::ERROR);
    }

    public function testCallGet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('get')
            ->with($key = 'foo')
            ->returns($value = 'bar');

        $result = $connection->__call('get', [$key]);

        $this->assertEquals($value, $result);
    }

    public function testGetPreservesDecodedValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $object = (object) ['name' => 'Hypervel'];

        foreach ([['nested' => true], $object, 42] as $index => $value) {
            $key = "key-{$index}";
            $connection->nativeClientForTest()
                ->expects('get')
                ->with($key)
                ->returns($value);

            $this->assertSame($value, $connection->__call('get', [$key]));
        }
    }

    public function testMget(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('mGet')
            ->with(['key1', 'key2', 'key3'])
            ->returns(['value1', false, 'value3']);

        $result = $connection->__call('mget', [['key1', 'key2', 'key3']]);

        $this->assertEquals(['value1', null, 'value3'], $result);
    }

    public function testMgetPreservesWholeCallFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('mGet')
            ->with(['key1', 'key2'])
            ->returns(false);

        $this->assertFalse($connection->__call('mget', [['key1', 'key2']]));
    }

    public function testMgetReturnsAnEmptyArrayWithoutCallingRedisForEmptyKeys(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $connection->nativeClientForTest()->expects('mGet')->never();

        $this->assertSame([], $connection->__call('mget', [[]]));
    }

    public function testSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('set')
            ->with('key', 'value', ['NX', 'EX' => 3600])
            ->returns(true);

        $result = $connection->__call('set', ['key', 'value', 'EX', 3600, 'NX']);

        $this->assertTrue($result);
    }

    public function testSetAcceptsNativeOptionsAndPreservesDecodedPreviousValues(): void
    {
        foreach ([['version' => 1], 42, (object) ['version' => 1]] as $previous) {
            $server = new RespServer;
            $serialized = serialize($previous);
            $server->start(static function ($client) use ($serialized): void {
                $argumentCount = (int) substr((string) fgets($client), 1);

                for ($index = 0; $index < $argumentCount; ++$index) {
                    $length = (int) substr((string) fgets($client), 1);
                    RespServer::readExact($client, $length + 2);
                }

                fwrite($client, '$' . strlen($serialized) . "\r\n{$serialized}\r\n");
            });
            [$host, $port] = $server->hostAndPort();
            $redis = new Redis;

            try {
                $this->assertTrue($redis->connect($host, $port));
                $this->assertTrue($redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP));
                $connection = $this->mockRedisConnection(transform: true);
                $connection->setActiveConnection($redis);

                $this->assertEquals(
                    $previous,
                    $connection->__call('set', ['key', ['version' => 2], ['GET', 'EX' => 3600]]),
                );
            } finally {
                $redis->close();
                $server->wait();
            }
        }
    }

    public function testSetPreservesBooleanNativeResults(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        foreach ([true, false] as $index => $result) {
            $key = "key-{$index}";
            $connection->nativeClientForTest()
                ->expects('set')
                ->with($key, 'value', ['GET'])
                ->returns($result);

            $this->assertSame($result, $connection->__call('set', [$key, 'value', ['GET']]));
        }
    }

    public function testSetnxAcceptsNonStringValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('setNx')
            ->with('key', 123)
            ->returns(true);

        $result = $connection->__call('setnx', ['key', 123]);

        $this->assertEquals(1, $result);
    }

    public function testHmgetSingleArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->returns(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', ['field1', 'field2']]);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmgetMultipleArgs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->returns(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', 'field1', 'field2']);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmgetPreservesWholeCallFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->returns(false);

        $this->assertFalse($connection->__call('hmget', ['hash', ['field1', 'field2']]));
    }

    public function testHmset(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->returns(true);

        $result = $connection->__call('hmset', ['hash', ['field1' => 'value1', 'field2' => 'value2']]);

        $this->assertTrue($result);
    }

    public function testHsetnxAcceptsNonStringValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $value = ['nested' => 'value'];

        $connection->nativeClientForTest()
            ->expects('hSetNx')
            ->with('hash', 'field', $value)
            ->returns(true);

        $result = $connection->__call('hsetnx', ['hash', 'field', $value]);

        $this->assertEquals(1, $result);
    }

    public function testLrem(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('lRem')
            ->with('list', 'value', 2)
            ->returns(1);

        $result = $connection->__call('lrem', ['list', 2, 'value']);

        $this->assertEquals(1, $result);
    }

    public function testBlpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('blPop')
            ->with('list1', 'list2', 10)
            ->returns(['list1', 'value']);

        $result = $connection->__call('blpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list1', 'value'], $result);
    }

    public function testBlpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('blPop')
            ->with('list1', 10)
            ->returns([]);

        $result = $connection->__call('blpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testBrpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('brPop')
            ->with('list1', 'list2', 10)
            ->returns(['list2', 'value']);

        $result = $connection->__call('brpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list2', 'value'], $result);
    }

    public function testBrpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('brPop')
            ->with('list1', 10)
            ->returns([]);

        $result = $connection->__call('brpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testSpop(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('sPop')
            ->with('myset', 2)
            ->returns(['member1', 'member2']);

        $result = $connection->__call('spop', ['myset', 2]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    #[DataProvider('scanOptions')]
    public function testScan(array $options, string $pattern, int $count): void
    {
        $client = new FakeRedisClient(scanResults: [['keys' => ['key1', 'key2'], 'iterator' => 0]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = null;

        $result = $connection->scan($cursor, ...$options);

        $this->assertSame([0, ['key1', 'key2']], $result);
        $this->assertSame(0, $cursor);
        $this->assertSame([['iterator' => null, 'pattern' => $pattern, 'count' => $count]], $client->getScanCalls());
    }

    /**
     * Provide positional and array scan options, including omitted defaults.
     *
     * @return array<string, array{array, string, int}>
     */
    public static function scanOptions(): array
    {
        return [
            'omitted' => [[], '*', 10],
            'empty options' => [[[]], '*', 10],
            'null pattern' => [[null, 20], '*', 20],
        ];
    }

    public function testTransformedScanInvalidatesOnTransportFailure(): void
    {
        $exception = new RedisException('Connection lost');
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->expects('scan')->with(null, '*', 10)->throws($exception);
        $redis->expects('getLastError')->returns(null);
        $connection = new PhpRedisConnectionStub($this->getContainer(), $this->getMockedPool());
        $connection->setActiveConnection($redis);
        $connection->shouldTransform(true);
        $cursor = null;

        try {
            $connection->scan($cursor);
            $this->fail('Expected the scan failure to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testScanWithOptions(): void
    {
        $client = new FakeRedisClient(scanResults: [['keys' => ['key1', 'key2'], 'iterator' => 0]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 0;

        $result = $connection->scan($cursor, 'prefix:*', 20);

        $this->assertSame([0, ['key1', 'key2']], $result);
        $this->assertSame([['iterator' => 0, 'pattern' => 'prefix:*', 'count' => 20]], $client->getScanCalls());
    }

    public function testScanWithEmptyResult(): void
    {
        $client = new FakeRedisClient;
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 0;

        $this->assertFalse($connection->scan($cursor, '*', 10));
        $this->assertSame([['iterator' => 0, 'pattern' => '*', 'count' => 10]], $client->getScanCalls());
    }

    public function testZscan(): void
    {
        $client = new FakeRedisClient(zScanResults: ['sortedset' => [['members' => ['member1' => 1.0, 'member2' => 2.0], 'iterator' => 0]]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 5;

        $result = $connection->zscan('sortedset', $cursor);

        $this->assertSame([0, ['member1' => 1.0, 'member2' => 2.0]], $result);
        $this->assertSame(0, $cursor);
        $this->assertSame([['key' => 'sortedset', 'iterator' => 5, 'pattern' => '*', 'count' => 10]], $client->getZScanCalls());
    }

    public function testHscan(): void
    {
        $client = new FakeRedisClient(hScanResults: ['hash' => [['fields' => ['field1' => 'value1', 'field2' => 'value2'], 'iterator' => 0]]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 5;

        $result = $connection->hscan('hash', $cursor);

        $this->assertSame([0, ['field1' => 'value1', 'field2' => 'value2']], $result);
        $this->assertSame(0, $cursor);
        $this->assertSame([['key' => 'hash', 'iterator' => 5, 'pattern' => '*', 'count' => 10]], $client->getHScanCalls());
    }

    public function testSscan(): void
    {
        $client = new FakeRedisClient(sScanResults: ['set' => [['members' => ['member1', 'member2'], 'iterator' => 0]]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 5;

        $result = $connection->sscan('set', $cursor);

        $this->assertSame([0, ['member1', 'member2']], $result);
        $this->assertSame(0, $cursor);
        $this->assertSame([['key' => 'set', 'iterator' => 5, 'pattern' => '*', 'count' => 10]], $client->getSScanCalls());
    }

    public function testEvalsha(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $redisConnection = $connection->nativeClientForTest();
        $redisConnection->expects('script')
            ->with('load', 'script')
            ->returns('sha1');

        $redisConnection->expects('evalSha')
            ->with('sha1', ['key1', 'key2'], 2)
            ->returns('result');

        $result = $connection->__call('evalsha', ['script', 2, 'key1', 'key2']);

        $this->assertEquals('result', $result);
    }

    public function testZaddWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zAdd')
            ->with('sortedset', ['NX', 'CH'], 1.0, 'member1', 2.0, 'member2')
            ->returns(2);

        $result = $connection->__call('zadd', ['sortedset', 'NX', 'CH', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZaddPreservesIncrementScoreAndFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zAdd')
            ->with('sortedset', ['INCR'], 1.5, 'member')
            ->returns(2.5);
        $connection->nativeClientForTest()
            ->expects('zAdd')
            ->with('sortedset', ['XX'], 1.5, 'missing')
            ->returns(false);

        $this->assertSame(2.5, $connection->__call('zadd', ['sortedset', 'INCR', 1.5, 'member']));
        $this->assertFalse($connection->__call('zadd', ['sortedset', 'XX', 1.5, 'missing']));
    }

    public function testZaddWithArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zAdd')
            ->with('sortedset', [], 1.0, 'member1', 2.0, 'member2')
            ->returns(2);

        $result = $connection->__call('zadd', ['sortedset', ['member1' => 1.0, 'member2' => 2.0]]);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zRangeByScore')
            ->with('sortedset', '1', '5', ['limit' => [0, 10]])
            ->returns(['member1', 'member2']);

        $result = $connection->__call('zrangebyscore', ['sortedset', '1', '5', ['limit' => ['offset' => 0, 'count' => 10]]]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    public function testZrangebyscorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zRangeByScore')
            ->with('sortedset', '1', '5', [])
            ->returns(false);

        $this->assertFalse($connection->__call('zrangebyscore', ['sortedset', '1', '5']));
    }

    public function testFlushdbAsync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('flushdb')
            ->with(true)
            ->returns(true);

        $result = $connection->__call('flushdb', ['ASYNC']);

        $this->assertTrue($result);
    }

    public function testFlushdbSync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('flushdb')
            ->with()
            ->returns(true);

        $result = $connection->__call('flushdb', []);

        $this->assertTrue($result);
    }

    public function testExecuteRaw(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('rawCommand')
            ->with('CUSTOM', 'arg1', 'arg2')
            ->returns('result');

        $result = $connection->__call('executeRaw', [['CUSTOM', 'arg1', 'arg2']]);

        $this->assertEquals('result', $result);
    }

    public function testZinterstoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zinterstore')
            ->with('output', ['set1', 'set2'], [1, 2], 'max')
            ->returns(3);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2'], ['weights' => [1, 2], 'aggregate' => 'max']]);

        $this->assertEquals(3, $result);
    }

    public function testZinterstorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->returns(false);

        $this->assertFalse($connection->__call('zinterstore', ['output', ['set1', 'set2']]));
    }

    public function testZunionstorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zunionstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->returns(false);

        $this->assertFalse($connection->__call('zunionstore', ['output', ['set1', 'set2']]));
    }

    public function testZunionstoreSimple(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->shouldTransform(false);

        $connection->nativeClientForTest()
            ->expects('zunionstore')
            ->returns(5);

        $result = $connection->__call('zunionstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(5, $result);
    }

    public function testGetTransformsFalseToNull(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('get')
            ->with('key')
            ->returns(false);

        $result = $connection->__call('get', ['key']);

        $this->assertNull($result);
    }

    public function testSetWithoutOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('set')
            ->with('key', 'value', null)
            ->returns(true);

        $result = $connection->__call('set', ['key', 'value']);

        $this->assertTrue($result);
    }

    public function testSetnxReturnsZeroOnFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('setNx')
            ->with('key', 'value')
            ->returns(false);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertEquals(0, $result);
    }

    public function testHmsetWithAlternatingKeyValuePairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->returns(true);

        // Laravel style: key, value, key, value
        $result = $connection->__call('hmset', ['hash', 'field1', 'value1', 'field2', 'value2']);

        $this->assertTrue($result);
    }

    public function testZaddWithScoreMemberPairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zAdd')
            ->with('zset', [], 1.0, 'member1', 2.0, 'member2')
            ->returns(2);

        $result = $connection->__call('zadd', ['zset', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithListLimitPassesThrough(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zRangeByScore')
            ->with('zset', '-inf', '+inf', ['limit' => [5, 20]])
            ->returns(['member1']);

        // Already in list format - passes through
        $result = $connection->__call('zrangebyscore', ['zset', '-inf', '+inf', ['limit' => [5, 20]]]);

        $this->assertEquals(['member1'], $result);
    }

    public function testZrevrangebyscoreWithLimitOption(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', ['limit' => [0, 5]])
            ->returns(['member2', 'member1']);

        $result = $connection->__call('zrevrangebyscore', ['zset', '+inf', '-inf', ['limit' => ['offset' => 0, 'count' => 5]]]);

        $this->assertEquals(['member2', 'member1'], $result);
    }

    public function testZrevrangebyscorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', [])
            ->returns(false);

        $this->assertFalse($connection->__call('zrevrangebyscore', ['zset', '+inf', '-inf']));
    }

    public function testZinterstoreDefaultsAggregate(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->returns(2);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(2, $result);
    }

    public function testCallWithoutTransformPassesDirectly(): void
    {
        $connection = $this->mockRedisConnection(transform: false);

        // Without transform, get() returns false (not null)
        $connection->nativeClientForTest()
            ->expects('get')
            ->with('key')
            ->returns(false);

        $result = $connection->__call('get', ['key']);

        $this->assertFalse($result);
    }

    public function testSerializedReturnsTrueWhenSerializerConfigured(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_PHP);

        $this->assertTrue($connection->serialized());
    }

    public function testSerializedReturnsFalseWhenNoSerializer(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_NONE);

        $this->assertFalse($connection->serialized());
    }

    public function testCompressedReturnsTrueWhenCompressionConfigured(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_LZF);

        $this->assertTrue($connection->compressed());
    }

    public function testCompressedReturnsFalseWhenNoCompression(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_NONE);

        $this->assertFalse($connection->compressed());
    }

    #[DataProvider('compressionAlgorithms')]
    public function testCompressionAlgorithmInspection(string $constant, ?string $enabledMethod): void
    {
        if (! defined($constant)) {
            $this->markTestSkipped("{$constant} is not defined.");
        }

        $redis = new Redis;
        $redis->setOption(Redis::OPT_COMPRESSION, constant($constant));
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($redis);

        foreach (['lzfCompressed', 'zstdCompressed', 'lz4Compressed'] as $method) {
            $this->assertSame($method === $enabledMethod, $connection->{$method}());
        }
    }

    /**
     * Provide native compression settings and their matching inspection methods.
     */
    public static function compressionAlgorithms(): array
    {
        return [
            'none' => ['Redis::COMPRESSION_NONE', null],
            'lzf' => ['Redis::COMPRESSION_LZF', 'lzfCompressed'],
            'zstd' => ['Redis::COMPRESSION_ZSTD', 'zstdCompressed'],
            'lz4' => ['Redis::COMPRESSION_LZ4', 'lz4Compressed'],
        ];
    }

    #[DataProvider('scanPrefixOptions')]
    public function testWithoutScanPrefixPreservesOtherOptionsAndRestores(bool $retry, bool $prefix): void
    {
        $redis = new Redis;
        $redis->setOption(Redis::OPT_PREFIX, 'app:');
        $redis->setOption(Redis::OPT_SCAN, $retry ? Redis::SCAN_RETRY : Redis::SCAN_NORETRY);
        $redis->setOption(Redis::OPT_SCAN, $prefix ? Redis::SCAN_PREFIX : Redis::SCAN_NOPREFIX);
        $originalOptions = $redis->getOption(Redis::OPT_SCAN);
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($redis);

        $result = $connection->withoutScanPrefix(function () use ($redis, $retry): string {
            $this->assertSame($retry ? Redis::SCAN_RETRY : Redis::SCAN_NORETRY, $redis->getOption(Redis::OPT_SCAN));
            $this->assertSame('app:', $redis->getOption(Redis::OPT_PREFIX));

            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
        $this->assertSame($originalOptions, $redis->getOption(Redis::OPT_SCAN));
    }

    /**
     * Provide independent retry and prefix settings.
     */
    public static function scanPrefixOptions(): array
    {
        return [
            'neither' => [false, false],
            'retry' => [true, false],
            'prefix' => [false, true],
            'retry and prefix' => [true, true],
        ];
    }

    public function testWithoutScanPrefixRestoresOptionsWhenCallbackThrows(): void
    {
        $redis = new Redis;
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_PREFIX);
        $originalOptions = $redis->getOption(Redis::OPT_SCAN);
        $connection = (new PhpRedisConnectionStub)->setActiveConnection($redis);
        $failure = new RuntimeException('Callback failed');

        try {
            $connection->withoutScanPrefix(function () use ($redis, $failure): never {
                $this->assertSame(Redis::SCAN_RETRY, $redis->getOption(Redis::OPT_SCAN));

                throw $failure;
            });
            $this->fail('Expected the callback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($originalOptions, $redis->getOption(Redis::OPT_SCAN));
    }

    public function testWithoutSerializationOrCompressionDisablesSerializerAndRestores(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = $connection->nativeClientForTest();

        // serialized() check + saving old value
        $redis->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_PHP);

        // compressed() check
        $redis->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_NONE);

        // Disable serialization
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        // Restore serialization
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        $result = $connection->withoutSerializationOrCompression(fn () => 'callback-result');

        $this->assertSame('callback-result', $result);
    }

    public function testWithoutSerializationOrCompressionDisablesCompressionAndRestores(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->mockRedisConnection();
        $redis = $connection->nativeClientForTest();

        // serialized() check
        $redis->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_NONE);

        // compressed() check + saving old value
        $redis->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_LZF);

        // Disable compression
        $redis->expects('setOption')
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);

        // Restore compression
        $redis->expects('setOption')
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);

        $result = $connection->withoutSerializationOrCompression(fn () => 'compressed-result');

        $this->assertSame('compressed-result', $result);
    }

    public function testWithoutSerializationOrCompressionDisablesBothAndRestores(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->mockRedisConnection();
        $redis = $connection->nativeClientForTest();

        // serialized() check + saving old value
        $redis->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_PHP);

        // compressed() check + saving old value
        $redis->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_LZF);

        // Disable both
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $redis->expects('setOption')
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);

        // Restore both
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $redis->expects('setOption')
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);

        $result = $connection->withoutSerializationOrCompression(fn () => 'both-result');

        $this->assertSame('both-result', $result);
    }

    public function testWithoutSerializationOrCompressionSkipsWhenNeitherConfigured(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = $connection->nativeClientForTest();

        // Neither serialized nor compressed
        $redis->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_NONE);
        $redis->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_NONE);

        // setOption should never be called
        $redis->expects('setOption')->never();

        $result = $connection->withoutSerializationOrCompression(fn () => 'no-change');

        $this->assertSame('no-change', $result);
    }

    public function testWithoutSerializationOrCompressionRestoresOnException(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = $connection->nativeClientForTest();

        // serialized() check + saving old value
        $redis->allows('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->returns(Redis::SERIALIZER_PHP);

        // compressed() check
        $redis->allows('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->returns(Redis::COMPRESSION_NONE);

        // Disable serialization
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        // Restore serialization even on exception
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        try {
            $connection->withoutSerializationOrCompression(function () {
                throw new RuntimeException('Callback failed');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Callback failed', $exception->getMessage());
        }
    }

    public function testIsClusterReturnsFalseForStandardRedis(): void
    {
        $connection = $this->mockRedisConnection();

        // Default stub uses a Redis mock, so isCluster() should return false
        $this->assertFalse($connection->isCluster());
    }

    public function testIsClusterReturnsTrueForRedisCluster(): void
    {
        $connection = new PhpRedisClusterConnectionStub;

        $this->assertTrue($connection->isCluster());
    }

    #[DataProvider('redisClusterHashTagProvider')]
    public function testHasHashTag(string $key, bool $expected): void
    {
        $this->assertSame($expected, RedisConnection::hasHashTag($key));
    }

    /**
     * Provide Redis keys with and without valid hash tags.
     */
    public static function redisClusterHashTagProvider(): array
    {
        return [
            ['plain-key', false],
            ['my{lock', false],
            ['{}', false],
            ['prefix{}suffix', false],
            ['{queue}', true],
            ['prefix{queue}suffix', true],
            ['{queue}:reserved', true],
        ];
    }

    public function testPackReturnsEmptyArrayForEmptyInput(): void
    {
        $connection = $this->mockRedisConnection();

        $result = $connection->pack([]);

        $this->assertSame([], $result);
    }

    public function testPackUsesNativePackMethod(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->expects('_pack')
            ->with('value1')
            ->returns('packed1');
        $connection->nativeClientForTest()
            ->expects('_pack')
            ->with('value2')
            ->returns('packed2');

        $result = $connection->pack(['value1', 'value2']);

        $this->assertSame(['packed1', 'packed2'], $result);
    }

    public function testPackPreservesArrayKeys(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->nativeClientForTest()
            ->expects('_pack')
            ->with('value1')
            ->returns('packed1');
        $connection->nativeClientForTest()
            ->expects('_pack')
            ->with('value2')
            ->returns('packed2');

        $result = $connection->pack(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'packed1',
            'key2' => 'packed2',
        ], $result);
    }

    public function testEvalWithShaCacheSucceedsOnFirstTry(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->nativeClientForTest()->allows('clearLastError')->returns(true);
        $script = 'return KEYS[1]';
        $sha = sha1($script);

        $connection->nativeClientForTest()
            ->expects('evalSha')
            ->with($sha, ['mykey', 'arg1', 'arg2'], 1)
            ->returns('mykey');

        $result = $connection->evalWithShaCache($script, ['mykey'], ['arg1', 'arg2']);

        $this->assertEquals('mykey', $result);
    }

    public function testEvalWithShaCacheThrowsOnNonNoscriptError(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->nativeClientForTest()->allows('clearLastError')->returns(true);
        $script = 'invalid lua syntax';
        $sha = sha1($script);

        $redisConnection = $connection->nativeClientForTest();

        $redisConnection->expects('evalSha')
            ->with($sha, ['mykey'], 1)
            ->returns(false);

        $redisConnection->expects('getLastError')
            ->returns('ERR Error compiling script');

        $this->expectException(LuaScriptException::class);
        $this->expectExceptionMessageIs('Lua script execution failed: ERR Error compiling script');

        $connection->evalWithShaCache($script, ['mykey']);
    }

    public function testEvalWithShaCacheInvalidatesAReadOnlyReplica(): void
    {
        $exception = new RedisException('READONLY replica is read-only');
        $redis = Double::for(Redis::class)->strict();
        $redis->allows('getMode')->returns(Redis::ATOMIC);
        $redis->expects('clearLastError');
        $redis->expects('evalSha')->with(sha1('return 1'), [], 0)->throws($exception);
        $redis->expects('getLastError')->returns($exception->getMessage());
        $redis->expects('eval')->never();
        $connection = new PhpRedisConnectionStub($this->getContainer(), $this->getMockedPool());
        $connection->setActiveConnection($redis);

        try {
            $connection->evalWithShaCache('return 1');
            $this->fail('Expected the replica error to propagate.');
        } catch (RedisException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testEvalWithShaCacheReturnsLegitimatelyFalseResult(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->nativeClientForTest()->allows('clearLastError')->returns(true);
        $script = 'return false';
        $sha = sha1($script);

        $redisConnection = $connection->nativeClientForTest();

        // Script returns false legitimately (no error)
        $redisConnection->expects('evalSha')
            ->with($sha, [], 0)
            ->returns(false);

        $redisConnection->expects('getLastError')
            ->returns(null); // No error - script legitimately returned false

        $result = $connection->evalWithShaCache($script);

        $this->assertFalse($result);
    }

    public function testEvalWithShaCacheWorksWithNoKeysOrArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->nativeClientForTest()->allows('clearLastError')->returns(true);
        $script = 'return 42';
        $sha = sha1($script);

        $connection->nativeClientForTest()
            ->expects('evalSha')
            ->with($sha, [], 0)
            ->returns(42);

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals(42, $result);
    }

    public function testEvalWithShaCacheWorksWithMultipleKeysAndArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->nativeClientForTest()->allows('clearLastError')->returns(true);
        $script = 'return {KEYS[1], KEYS[2], ARGV[1], ARGV[2]}';
        $sha = sha1($script);

        $connection->nativeClientForTest()
            ->expects('evalSha')
            ->with($sha, ['key1', 'key2', 'arg1', 'arg2'], 2)
            ->returns(['key1', 'key2', 'arg1', 'arg2']);

        $result = $connection->evalWithShaCache($script, ['key1', 'key2'], ['arg1', 'arg2']);

        $this->assertEquals(['key1', 'key2', 'arg1', 'arg2'], $result);
    }

    public function testEvalWithShaCacheClearsLastErrorBeforeEvalSha(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return "ok"';
        $sha = sha1($script);

        $redisConnection = $connection->nativeClientForTest();

        // Verify clearLastError is called before evalSha using ordered expectations
        $redisConnection->expects('clearLastError')
            ->ordered();

        $redisConnection->expects('evalSha')
            ->with($sha, [], 0)
            ->ordered()
            ->returns('ok');

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals('ok', $result);
    }

    public function testSpopWithoutCountReturnsSingleElement(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // Without count, phpredis sPop returns a single string
        $connection->nativeClientForTest()
            ->expects('sPop')
            ->with('myset')
            ->returns('member1');

        $result = $connection->__call('spop', ['myset']);

        $this->assertSame('member1', $result);
    }

    public function testSpopWithCountReturnsArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // With count, phpredis sPop returns an array
        $connection->nativeClientForTest()
            ->expects('sPop')
            ->with('myset', 3)
            ->returns(['member1', 'member2', 'member3']);

        $result = $connection->__call('spop', ['myset', 3]);

        $this->assertSame(['member1', 'member2', 'member3'], $result);
    }

    public function testSpopWithoutCountReturnsFalseForEmptySet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->nativeClientForTest()
            ->expects('sPop')
            ->with('emptyset')
            ->returns(false);

        $result = $connection->__call('spop', ['emptyset']);

        $this->assertFalse($result);
    }

    public function testEvalReordersArguments(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $connection->nativeClientForTest()->expects('eval')
            ->with('return KEYS[1]', ['mykey'], 1)
            ->returns('result');

        $this->assertSame('result', $connection->eval('return KEYS[1]', 1, 'mykey'));
    }

    public function testEvalReordersMultipleArguments(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $connection->nativeClientForTest()->expects('eval')
            ->with('return {KEYS[1], ARGV[1]}', ['mykey', 'myarg'], 1)
            ->returns('result');

        $this->assertSame('result', $connection->eval('return {KEYS[1], ARGV[1]}', 1, 'mykey', 'myarg'));
    }

    public function testEvalWithNoKeysOrArguments(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $connection->nativeClientForTest()->expects('eval')
            ->with('return 42', [], 0)
            ->returns('result');

        $this->assertSame('result', $connection->eval('return 42', 0));
    }

    #[DataProvider('unsupportedSubscriptionProvider')]
    public function testSubscriptionsThrowOnPooledConnection(string $command): void
    {
        $connection = $this->mockRedisConnection();

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIsOrContains("Cannot call {$command}() on a pooled RedisConnection.");

        $connection->__call(strtoupper($command), [['channel1'], function () {}]);
    }

    public static function unsupportedSubscriptionProvider(): array
    {
        return [
            ['subscribe'],
            ['psubscribe'],
            ['ssubscribe'],
        ];
    }

    public function testResetIsRejectedBeforeNativeDispatchAndPreservesWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(Argument::type(RedisConnection::class));
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('watch')->with('key')->returns(true);
        $redis->expects('reset')->never();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);
        $connection->shouldTransform(false);

        $connection->__call('WATCH', ['key']);

        try {
            $connection->__call('RESET', []);
            $this->fail('Expected pooled RESET to be rejected.');
        } catch (BadMethodCallException $exception) {
            $this->assertSame(
                'Cannot call reset() on a pooled Redis connection because it clears '
                . 'the authentication and selected database owned by the pool. '
                . 'For facade-managed connections, use Redis::discard(), Redis::unwatch(), '
                . 'or Redis::exec(). On a held connection, call discardTransaction(), '
                . 'unwatch(), or exec() on that same connection.',
                $exception->getMessage(),
            );
        }

        $connection->release();
    }

    public function testReconnectSetsSerializerOption(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['serializer' => Redis::SERIALIZER_PHP]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsPrefixOption(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')
            ->with(Redis::OPT_PREFIX, 'myapp:');

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['prefix' => 'myapp:']]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsPackIgnoreNumbersOnStandaloneConnection(): void
    {
        if (! defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis does not support OPT_PACK_IGNORE_NUMBERS.');
        }

        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')
            ->with(Redis::OPT_PACK_IGNORE_NUMBERS, true)
            ->returns(true);

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['pack_ignore_numbers' => true]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectRejectsNamedPackIgnoreNumbersWhenPhpRedisDoesNotSupportIt(): void
    {
        if (defined(Redis::class . '::OPT_PACK_IGNORE_NUMBERS')) {
            $this->markTestSkipped('PhpRedis supports OPT_PACK_IGNORE_NUMBERS.');
        }

        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->allows('setOption')->returns(true);

        $this->expectException(InvalidRedisOptionException::class);
        $this->expectExceptionMessageIs('The redis option `pack_ignore_numbers` requires PhpRedis 6.2 or later.');

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['pack_ignore_numbers' => true]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsConnectionLevelPhpRedisOptions(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')
            ->with(Redis::OPT_READ_TIMEOUT, 5.0);
        $redis->expects('setOption')
            ->with(Redis::OPT_MAX_RETRIES, 4);
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_CONSTANT);
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_BASE, 200);
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_CAP, 2000);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['read_timeout' => 5.0, 'max_retries' => 4, 'backoff_algorithm' => 'constant', 'backoff_base' => 200, 'backoff_cap' => 2000]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectDoesNotSetReadTimeoutOptionWhenEmpty(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['read_timeout' => 0.0]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testParseBackoffAlgorithmReturnsIntegerAsIs(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DEFAULT);

        $redis->allows('setOption')->with(Argument::not(Redis::OPT_BACKOFF_ALGORITHM), Argument::any())->returns(true);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['backoff_algorithm' => Redis::BACKOFF_ALGORITHM_DEFAULT]), $redis) extends PhpRedisConnection {
            /**
             * Create the connection with a fake Redis client.
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            /**
             * Return the fake Redis client.
             */
            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testParseBackoffAlgorithmParsesValidNames(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->assertSame(Redis::BACKOFF_ALGORITHM_DEFAULT, $connection->parseBackoffAlgorithm('default'));
        $this->assertSame(Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER, $connection->parseBackoffAlgorithm('decorrelated_jitter'));
        $this->assertSame(Redis::BACKOFF_ALGORITHM_EQUAL_JITTER, $connection->parseBackoffAlgorithm('equal_jitter'));
        $this->assertSame(Redis::BACKOFF_ALGORITHM_EXPONENTIAL, $connection->parseBackoffAlgorithm('exponential'));
        $this->assertSame(Redis::BACKOFF_ALGORITHM_UNIFORM, $connection->parseBackoffAlgorithm('uniform'));
        $this->assertSame(Redis::BACKOFF_ALGORITHM_CONSTANT, $connection->parseBackoffAlgorithm('constant'));
    }

    public function testParseBackoffAlgorithmThrowsForInvalidName(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();

        $this->expectExceptionObject(new InvalidRedisOptionException('Algorithm [bogus] is not a valid PhpRedis backoff algorithm.'));

        $redis->allows('setOption')->returns(true);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['backoff_algorithm' => 'bogus']), $redis) extends PhpRedisConnection {
            /**
             * Create the connection with a fake Redis client.
             */
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            /**
             * Return the fake Redis client.
             */
            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testFormatHostPrefixesConfiguredSchemeWhenHostHasNoScheme(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->assertSame('tls://127.0.0.1', $connection->formatHost([
            'host' => '127.0.0.1',
            'scheme' => 'tls',
        ]));
    }

    public function testFormatHostDoesNotDuplicateMatchingScheme(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->assertSame('tls://127.0.0.1', $connection->formatHost([
            'host' => 'tls://127.0.0.1',
            'scheme' => 'tls',
        ]));
    }

    public function testFormatHostThrowsOnConflictingScheme(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->expectExceptionObject(new InvalidArgumentException('The scheme configured in the Redis host option must match the scheme option.'));

        $connection->formatHost([
            'host' => 'tcp://127.0.0.1',
            'scheme' => 'tls',
        ]);
    }

    public function testFormatHostAllowsCaseInsensitiveMatchingScheme(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->assertSame('TLS://127.0.0.1', $connection->formatHost([
            'host' => 'TLS://127.0.0.1',
            'scheme' => 'tls',
        ]));
    }

    public function testFormatHostThrowsWhenHostIsMissing(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->expectExceptionObject(new InvalidArgumentException('Redis host must be a non-empty string.'));

        $connection->formatHost([
            'scheme' => 'tls',
        ]);
    }

    public function testFormatHostThrowsWhenHostIsNull(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->expectExceptionObject(new InvalidArgumentException('Redis host must be a non-empty string.'));

        $connection->formatHost([
            'host' => null,
            'scheme' => 'tls',
        ]);
    }

    public function testFormatHostThrowsWhenHostIsEmpty(): void
    {
        $connection = new ClassInvoker(new PhpRedisConnectionStub);

        $this->expectExceptionObject(new InvalidArgumentException('Redis host must be a non-empty string.'));

        $connection->formatHost([
            'host' => '',
        ]);
    }

    public function testReconnectThrowsOnUnknownOption(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();

        $this->expectException(InvalidRedisOptionException::class);
        $this->expectExceptionMessageIs('The redis option key `bogus` is invalid.');

        $redis->allows('setOption')->returns(true);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['bogus' => 'value']]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectSetsNumericOptions(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();

        // Numeric keys bypass the match statement and pass directly
        $redis->expects('setOption')
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => [Redis::OPT_SERIALIZER => Redis::SERIALIZER_JSON]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectAuthenticatesWhenAuthConfigured(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('auth')
            ->with('secret');

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['password' => 'secret']), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testReconnectDoesNotAuthenticateWhenAuthEmpty(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('auth')->never();

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['password' => '']), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }
        };
    }

    public function testCloseInvokesNativeRedisClose(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('close')->returns(true);

        $connection->setActiveConnection($redis);
        $this->assertNotNull($connection->client());

        $result = $connection->close();

        $this->assertTrue($result);
        $this->assertNull($connection->client());
    }

    public function testCloseSwallowsExceptionFromNativeRedisClose(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('close')
            ->throws(new RedisException('connection already closed'));

        $connection->setActiveConnection($redis);

        $result = $connection->close();

        $this->assertTrue($result);
        $this->assertNull($connection->client());
    }

    public function testClusterTransformFiresInAtomicMode(): void
    {
        $connection = new PhpRedisClusterConnectionStub;
        $connection->shouldTransform(true);

        // In atomic mode, isQueueingMode() returns false, so transforms fire
        $clusterMock = Double::for(RedisCluster::class)->strict();
        $clusterMock->allows('getMode')->returns(Redis::ATOMIC);
        $clusterMock->expects('setNx')
            ->with('key', 'value')
            ->returns(true);

        $connection->setActiveConnection($clusterMock);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testReconnectClearsInvalidState(): void
    {
        $pool = $this->getMockedPool();
        $redis = Double::for(Redis::class)->strict();
        $redis->allows('select')->returns(true);
        $redis->expects('isConnected')->never();

        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 1]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }

            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }
        };

        $connection->invalidate();

        $this->assertTrue($connection->isInvalidForTest());

        $connection->reconnect();

        $this->assertFalse($connection->isInvalidForTest());
    }

    public function testInvalidStateIsNotMaskedByFreshReleaseTime(): void
    {
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['max_idle_time' => 60.0]));

        $redis = Double::for(Redis::class)->strict();

        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }

            public function setLastReleaseTimeForTest(float $lastReleaseTime): void
            {
                $this->lastReleaseTime = $lastReleaseTime;
            }
        };

        $connection->invalidate();
        $connection->setLastReleaseTimeForTest(hrtime(true) / 1e9);

        $this->assertFalse($connection->check());
    }

    public function testCheckDoesNotResetActivityTimestamp(): void
    {
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')->returns(PoolOptions::fromArray(['max_idle_time' => 60.0]));
        $redis = Double::for(Redis::class)->strict();

        $redis->allows('setOption')->returns(true);

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                ConnectionPool $pool,
                array $config,
                private Redis $fakeRedis,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return $this->fakeRedis;
            }

            public function prepareForIdleCheck(): float
            {
                $this->availableForReuse = true;
                $this->lastReleaseTime = hrtime(true) / 1e9;

                return $this->lastUseTime;
            }

            public function getLastUseTimeForTest(): float
            {
                return $this->lastUseTime;
            }
        };

        $lastUseTime = $connection->prepareForIdleCheck();

        $this->assertTrue($connection->check());
        $this->assertSame($lastUseTime, $connection->getLastUseTimeForTest());
    }

    public function testScanWithArrayOptions(): void
    {
        $client = new FakeRedisClient(scanResults: [['keys' => ['key1', 'key2'], 'iterator' => 0]]);
        $connection = $this->mockRedisConnection(transform: true);
        $connection->setActiveConnection($client);
        $cursor = 0;

        $result = $connection->scan($cursor, ['match' => 'prefix:*', 'count' => 20]);

        $this->assertSame([0, ['key1', 'key2']], $result);
        $this->assertSame([['iterator' => 0, 'pattern' => 'prefix:*', 'count' => 20]], $client->getScanCalls());
    }

    /**
     * Create a complete standalone Redis connection record.
     */
    protected function standaloneConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'url' => null,
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'name' => null,
        ], $overrides);
    }

    /**
     * Create a complete Sentinel Redis connection record.
     */
    protected function sentinelConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'database' => 0,
            'name' => null,
            'sentinel' => [
                'enabled' => true,
                'master_name' => 'primary',
                'nodes' => ['127.0.0.1:26379'],
                'username' => null,
                'password' => null,
                'timeout' => 1.0,
                'read_timeout' => 1.0,
                'context' => [],
            ],
        ], $overrides);
    }

    /**
     * Create a complete Cluster Redis connection record.
     */
    protected function clusterConfig(array $overrides = []): array
    {
        return array_replace($this->baseConnectionConfig(), [
            'scheme' => 'tcp',
            'cluster' => [
                'enabled' => true,
                'seeds' => ['tcp://127.0.0.1:7000'],
            ],
        ], $overrides);
    }

    /**
     * Create the members shared by every Redis connection topology.
     */
    protected function baseConnectionConfig(): array
    {
        return [
            'scheme' => null,
            'username' => null,
            'password' => null,
            'timeout' => 1.0,
            'read_timeout' => 0.0,
            'context' => [],
            'options' => [],
            'prefix' => null,
            'events' => false,
            'max_retries' => 3,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base' => 100,
            'backoff_cap' => 1000,
            'tcp_keepalive' => 0,
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => null,
            ],
        ];
    }

    /**
     * Expect the default connection-level phpredis options.
     */
    protected function expectDefaultConnectionOptions(Redis $redis): void
    {
        $redis->expects('setOption')->with(Redis::OPT_MAX_RETRIES, 3)->returns(true);
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER)
            ->returns(true);
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_BASE, 100)->returns(true);
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_CAP, 1000)->returns(true);
    }

    /**
     * Create a connected client ready to return to the pool on its default database.
     */
    protected function mockIdleRedisClient(): Redis
    {
        $redis = Double::for(Redis::class)->strict();
        $redis->expects('getMode')->returns(Redis::ATOMIC);
        $redis->allows('isConnected')->returns(true);
        $redis->allows('getDBNum')->returns(0);

        return $redis;
    }

    /**
     * Create a Redis connection test double.
     */
    protected function mockRedisConnection(?ContainerContract $container = null, ?ConnectionPool $pool = null, array $options = [], bool $transform = false): RedisConnection
    {
        $connection = new PhpRedisConnectionStub(
            $container ?? $this->getContainer(),
            $pool ?? $this->getMockedPool(),
            $this->standaloneConfig($options)
        );

        $client = Double::for(Redis::class)->strict();
        $client->allows('getMode')->returns(Redis::ATOMIC);
        $connection->setActiveConnection($client);

        if ($transform) {
            $connection->shouldTransform(true);
        }

        return $connection;
    }

    protected function getMockedPool(): ConnectionPool
    {
        $pool = Double::for(ConnectionPool::class)->strict();
        $pool->allows('getOptions')
            ->returns(PoolOptions::fromArray([]));

        return $pool;
    }

    protected function getContainer(array $definitions = []): Container
    {
        $container = new Container;

        foreach ($definitions as $abstract => $concrete) {
            $container->singleton($abstract, $concrete);
        }

        return $container;
    }

    /**
     * Normalize an SSL context for a standalone connection.
     */
    protected function callNormalizeContext(array $context): array
    {
        return (new ClassInvoker(new PhpRedisConnectionStub))->normalizeContext($context);
    }
}
