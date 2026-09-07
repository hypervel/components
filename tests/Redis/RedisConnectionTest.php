<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use BadMethodCallException;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\Exceptions\ConnectionException;
use Hypervel\Pool\PoolOption;
use Hypervel\Redis\Exceptions\InvalidRedisOptionException;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel as SwooleChannel;
use Symfony\Component\Process\Process;
use Throwable;
use TypeError;

class RedisConnectionTest extends TestCase
{
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
        $pool->shouldReceive('release')->once();

        $connection = $this->mockRedisConnection(pool: $pool);
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $connection->setActiveConnection($redis);
        $connection->shouldTransform(true);

        $connection->release();

        $this->assertFalse($connection->getShouldTransform());
    }

    public function testSuccessfulAtomicSelectTracksTheAppliedDatabase(): void
    {
        $redis = m::mock(Redis::class);
        $redis->expects('select')->with(2)->andReturnTrue();
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
        $redis = m::mock(Redis::class);
        $redis->expects('select')->with(2)->andReturnFalse();
        $redis->expects('select')->with(3)->andReturnSelf();
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
        $pool->shouldReceive('release')->once();

        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->once()->with(1)->andReturn(true);
        $redis->shouldReceive('select')->once()->with(1)->andReturn(true);
        $redis->expects('select')->with(2)->andReturnTrue();
        $redis->shouldReceive('getMode')->once()->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')->andReturnTrue();
        $redis->expects('getDBNum')->andReturn(2);

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 1]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')->andReturnTrue();
        $redis->expects('getDBNum')->andReturn(2);
        $redis->expects('select')->with(0)->andReturnTrue();
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
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')->andReturnFalse();
        $redis->shouldNotReceive('getDBNum');
        $redis->shouldNotReceive('select');
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleaseDiscardsAConnectionInMultiMode(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::MULTI);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testReleaseDiscardsAConnectionInPipelineMode(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::PIPELINE);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testReleaseDiscardsAConnectionWithAnActiveWatch(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
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
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects(strtolower($command))->andReturn($result);
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
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
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('unwatch')->andReturnFalse();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $this->assertFalse($connection->__call('unwatch', []));
        $connection->release();
    }

    public function testNativeDiscardClearsTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('discard')->andReturnTrue();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $this->assertTrue($connection->discardTransaction());
        $connection->release();
    }

    public function testClearWatchStateAllowsOrdinaryRelease(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $connection->clearWatchState();
        $connection->release();
    }

    public function testReconnectBeginsWithNoTrackedWatchState(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')->twice()->andReturnFalse();
        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $redis, $cancellation) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('close')->andReturnTrue();
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->__call('watch', ['key']);
        $connection->close();
        $connection->release();
    }

    public function testReleaseChecksNativeModeBeforeRestoringDatabase(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('select')
            ->with(2)
            ->globally()
            ->ordered()
            ->andReturnTrue();
        $redis->expects('getMode')
            ->globally()
            ->ordered()
            ->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')
            ->globally()
            ->ordered()
            ->andReturnTrue();
        $redis->expects('getDBNum')
            ->globally()
            ->ordered()
            ->andReturn(2);
        $redis->expects('select')
            ->with(0)
            ->globally()
            ->ordered()
            ->andReturnTrue();
        $connection = $this->mockRedisConnection(pool: $pool, options: ['database' => 0]);
        $connection->setActiveConnection($redis);
        $connection->__call('select', [2]);

        $connection->release();
    }

    public function testModeDetectionFailureInvalidatesAndReleasesConnection(): void
    {
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andThrow(new RuntimeException('Mode failed.'));
        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig()) extends PhpRedisConnectionStub {
            public function isInvalidForTest(): bool
            {
                return $this->invalid;
            }
        };
        $connection->setActiveConnection($redis);

        $connection->release();

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleasePreservesExactCancellationWithoutDispatchingReleaseObservers(): void
    {
        $cancellation = new CanceledException('mode canceled');
        $releaseObserved = false;
        $container = $this->getContainer();
        $dispatcher = new Dispatcher($container);
        $dispatcher->listen(ReleaseConnection::class, function () use (&$releaseObserved): void {
            $releaseObserved = true;
        });
        $container->instance('events', $dispatcher);
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: [ReleaseConnection::class]));
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $pool->shouldNotReceive('discard');
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andThrow($cancellation);
        $redis->shouldNotReceive('close');
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertFalse($releaseObserved);
        $this->assertTrue($connection->isInvalidForTest());
        $this->assertNull(
            (new ReflectionProperty(RedisConnection::class, 'database'))->getValue($connection),
        );
    }

    public function testReleaseNormalizesWrappedPhpRedisCancellation(): void
    {
        $nativeFailure = new RedisException('mode canceled');
        $releaseObserved = false;
        $container = $this->getContainer();
        $dispatcher = new Dispatcher($container);
        $dispatcher->listen(ReleaseConnection::class, function () use (&$releaseObserved): void {
            $releaseObserved = true;
        });
        $container->instance('events', $dispatcher);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('log');
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(events: [ReleaseConnection::class]));
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $pool->shouldNotReceive('discard');
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andThrow($nativeFailure);
        $redis->shouldNotReceive('close');
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        $exception = $this->captureCancellationAtBoundary(function () use ($connection): void {
            $connection->release();
        });

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame($nativeFailure, $exception->getPrevious());
        $this->assertFalse($releaseObserved);
        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleasePreservesOperationCancellationOverPoolCleanupCancellation(): void
    {
        $cancellation = new CanceledException('mode canceled');
        $cleanupCancellation = new CanceledException('pool release canceled');
        $pool = $this->getMockedPool();
        $pool->expects('release')->andThrow($cleanupCancellation);
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andThrow($cancellation);
        $connection = $this->mockRedisConnection(pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCancellationFromQueueingWarningInvalidatesAndReleasesWithoutDiscarding(): void
    {
        $cancellation = new CanceledException('logging canceled');
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->expects('log')
            ->with(
                LogLevel::CRITICAL,
                'Discarding Redis connection left in MULTI or PIPELINE mode.'
            )
            ->andThrow($cancellation);
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $pool->shouldNotReceive('discard');
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::MULTI);
        $redis->shouldNotReceive('close');
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        try {
            $connection->release();
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertTrue($connection->isInvalidForTest());
    }

    public function testReleaseNormalizesWrappedCancellationFromNativeClose(): void
    {
        $nativeFailure = new RedisException('close canceled');
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldNotReceive('log');
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $pool = $this->getMockedPool();
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $pool->shouldNotReceive('discard');
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
        $redis->expects('isConnected')->andThrow(new RuntimeException('validation failed'));
        $redis->expects('close')->andThrow($nativeFailure);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        $exception = $this->captureCancellationAtBoundary(function () use ($connection): void {
            $connection->release();
        });

        $this->assertInstanceOf(CanceledException::class, $exception);
        $this->assertSame($nativeFailure, $exception->getPrevious());
        $this->assertNull($connection->client());
    }

    #[DataProvider('databaseRestoreFailureProvider')]
    public function testDatabaseRestoreFailureClosesTheNativeGenerationBeforeReconnect(string $failureMode): void
    {
        $pool = $this->getMockedPool();
        $pool->shouldReceive('getName')->andReturn('default');
        $pool->expects('release')->with(m::type(RedisConnection::class));
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->expects('log')->with(
            LogLevel::CRITICAL,
            m::on(static fn (string $message): bool => str_starts_with($message, 'Release connection failed, caused by ')),
        );
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $oldRedis = m::mock(Redis::class);
        $newRedis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->andReturnTrue();
        $oldRedis->expects('getMode')->andReturn(Redis::ATOMIC);
        $oldRedis->expects('isConnected')->andReturnTrue();
        $oldRedis->expects('getDBNum')->once()->andReturn(2);
        $restore = $oldRedis->expects('select')->with(0);

        if ($failureMode === 'false') {
            $restore->andReturnFalse();
        } else {
            $restore->andThrow(new RuntimeException('Select failed.'));
        }

        $oldRedis->expects('close')->andReturnTrue();
        $newRedis->shouldNotReceive('select');
        $connection = new class($container, $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->expects('log')
            ->with(
                LogLevel::CRITICAL,
                'Discarding Redis connection left in MULTI or PIPELINE mode.'
            )
            ->andThrow(new RuntimeException('Logging failed.'));
        $container = $this->getContainer();
        $container->instance(StdoutLoggerInterface::class, $logger);
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::MULTI);
        $connection = $this->mockRedisConnection(container: $container, pool: $pool);
        $connection->setActiveConnection($redis);

        $connection->release();
    }

    public function testDiscardOwnershipFailurePropagates(): void
    {
        $exception = new RuntimeException('Discard ownership failed.');
        $pool = $this->getMockedPool();
        $pool->expects('discard')->andThrow($exception);
        $redis = m::mock(Redis::class);
        $redis->expects('getMode')->andReturn(Redis::MULTI);
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
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->twice()->with(2)->andReturn(true);
        $redis->expects('isConnected')->andReturnTrue();
        $redis->expects('getDBNum')->andReturn(2);

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $oldRedis = m::mock(Redis::class);
        $newRedis = m::mock(Redis::class);
        $secondNewRedis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $this->expectDefaultConnectionOptions($secondNewRedis);
        $oldRedis->expects('isConnected')->andReturnTrue();
        $oldRedis->expects('getDBNum')->andReturn(2);
        $newRedis->expects('select')->with(2)->andReturnTrue();
        $newRedis->expects('isConnected')->andReturnFalse();
        $newRedis->shouldNotReceive('getDBNum');
        $secondNewRedis->expects('select')->with(2)->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis, $secondNewRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool->expects('getName')->andReturn('default');
        $oldRedis = m::mock(Redis::class);
        $newRedis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->andReturnTrue();
        $oldRedis->shouldNotReceive('isConnected');
        $oldRedis->shouldNotReceive('getDBNum');
        $newRedis->expects('select')->with(2)->andReturnFalse();
        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 2]), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($redis);
        $redis->shouldNotReceive('select');
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $oldRedis = m::mock(Redis::class);
        $newRedis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->andReturnTrue();
        $oldRedis->expects('isConnected')->andReturnFalse();
        $oldRedis->shouldNotReceive('getDBNum');
        $newRedis->expects('select')->with(2)->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $oldRedis = m::mock(Redis::class);
        $newRedis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($oldRedis);
        $this->expectDefaultConnectionOptions($newRedis);
        $oldRedis->expects('select')->with(2)->andReturnTrue();
        $oldRedis->shouldNotReceive('isConnected');
        $oldRedis->shouldNotReceive('getDBNum');
        $newRedis->expects('select')->with(2)->andReturnTrue();
        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), [$oldRedis, $newRedis]) extends PhpRedisConnection {
            /**
             * @param Redis[] $clients
             */
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $sentinelFactory = m::mock(RedisSentinelFactory::class);
        $sentinelFactory->expects('resolveMaster')->andReturn(['127.0.0.1', 6380]);
        $container = m::mock(ContainerContract::class);
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->andReturn($sentinelFactory);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $container->shouldReceive('has')->andReturnFalse();
        $redis = m::mock(Redis::class);
        $redis->expects('setOption')->with(Redis::OPT_READ_TIMEOUT, 2.5)->andReturnTrue();
        $this->expectDefaultConnectionOptions($redis);
        $connection = new class($container, $this->getMockedPool(), $this->sentinelConfig(['timeout' => 1.5, 'read_timeout' => 2.5, 'context' => ['stream' => ['tcp_nodelay' => true]]]), $redis) extends PhpRedisConnection {
            private array $createdConfig = [];

            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $sentinelFactory = m::mock(RedisSentinelFactory::class);
        $sentinelFactory->expects('resolveMaster')->andThrow($cancellation);
        $container = m::mock(ContainerContract::class);
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->andReturn($sentinelFactory);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $container->shouldReceive('has')->andReturnFalse();

        try {
            new PhpRedisConnection($container, $this->getMockedPool(), $this->sentinelConfig());
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testSentinelResolutionPreservesTheUnderlyingFailure(): void
    {
        $failure = new RuntimeException('sentinel unavailable');
        $sentinelFactory = m::mock(RedisSentinelFactory::class);
        $sentinelFactory->expects('resolveMaster')->andThrow($failure);
        $container = m::mock(ContainerContract::class);
        $container->expects('make')
            ->with(RedisSentinelFactory::class)
            ->andReturn($sentinelFactory);
        $container->shouldReceive('bound')->with('events')->andReturnFalse();
        $container->shouldReceive('has')->andReturnFalse();

        try {
            new PhpRedisConnection($container, $this->getMockedPool(), $this->sentinelConfig());
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

    public function testNormalizeContextAcceptsEverySupportedShape(): void
    {
        $connection = new class extends PhpRedisConnectionStub {
            public function normalizeContextForTest(array $context): array
            {
                return $this->normalizeContext($context);
            }
        };
        $options = ['verify_peer' => false, 'cafile' => '/tmp/ca.pem'];

        $this->assertSame(['stream' => $options], $connection->normalizeContextForTest($options));
        $this->assertSame(['stream' => $options], $connection->normalizeContextForTest(['ssl' => $options]));
        $this->assertSame(['stream' => $options], $connection->normalizeContextForTest(['stream' => $options]));
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
        if (SWOOLE_VERSION_ID <= 60202) {
            $this->markTestSkipped(
                'Requires the hooked TLS cancellation fix from https://github.com/swoole/swoole-src/pull/6182.'
            );
        }

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
        $this->expectExceptionMessage('Connection reconnect failed');

        new class($this->getContainer(), $this->getMockedPool(), $this->clusterConfig(['cluster' => ['enabled' => true, 'seeds' => []]])) extends PhpRedisClusterConnection {
        };
    }

    public function testQueueingModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testPipelineModeBypassesTransformedSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::PIPELINE);
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesSetArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('set')
            ->once()
            ->with('key', 'value', ['NX', 'EX' => 600])
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 'EX', 600, 'NX']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModePreservesNativeSetOptionsAndRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('set')
            ->once()
            ->with('key', 'value', ['GET', 'EX' => 600])
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', ['GET', 'EX' => 600]]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesHmsetArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('hMSet')
            ->once()
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('hmset', ['hash', 'field1', 'value1', 'field2', 'value2']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesLremArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('lRem')
            ->once()
            ->with('list', 'value', 2)
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('lrem', ['list', 2, 'value']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZaddArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('zAdd')
            ->once()
            ->with('sortedset', ['NX', 'CH'], 1.0, 'member1', 2.0, 'member2')
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zadd', ['sortedset', 'NX', 'CH', 1.0, 'member1', 2.0, 'member2']);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZrangebyscoreArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('zRangeByScore')
            ->once()
            ->with('sortedset', '1', '5', ['limit' => [0, 10]])
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zrangebyscore', ['sortedset', '1', '5', ['limit' => ['offset' => 0, 'count' => 10]]]);

        $this->assertSame($redis, $result);
    }

    public function testQueueingModeReshapesZinterstoreArgumentsButPreservesRawQueuedReturn(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('zinterstore')
            ->once()
            ->with('output', ['set1', 'set2'], [1, 2], 'max')
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2'], ['weights' => [1, 2], 'aggregate' => 'max']]);

        $this->assertSame($redis, $result);
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
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::MULTI);
        $redis->shouldReceive('rawCommand')
            ->once()
            ->with('CUSTOM', 'arg1', 'arg2')
            ->andReturnSelf();

        $connection->setActiveConnection($redis);

        $result = $connection->__call('executeRaw', [['CUSTOM', 'arg1', 'arg2']]);

        $this->assertSame($redis, $result);
    }

    public function testTransformDisabledSetUsesNativeSignatureWithoutInspectingMode(): void
    {
        $connection = $this->mockRedisConnection(transform: false);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->never();
        $redis->shouldReceive('set')->once()->with('key', 'value', 600)->andReturn(true);

        $connection->setActiveConnection($redis);

        $result = $connection->__call('set', ['key', 'value', 600]);

        $this->assertTrue($result);
    }

    public function testTypeErrorsAreNotRetried(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('getMode')->once()->andReturn(Redis::ATOMIC);
        $redis->shouldReceive('set')
            ->once()
            ->with('key', 'value', 600)
            ->andThrow(new TypeError('Invalid native Redis argument.'));
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
        RedisConnection::macro('CustomCommand', fn () => 'exact');
        RedisConnection::macro('reset', fn () => 'shadowed');
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);
        $redis->expects('customcommand')->once()->andReturn('native');
        $redis->expects('reset')->never();
        $connection->setActiveConnection($redis);

        $this->assertSame('exact', $connection->__call('CustomCommand', []));
        $this->assertSame('native', $connection->__call('customcommand', []));
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
        $redis = m::mock(Redis::class);
        $redis->expects('get')->once()->with('key')->andThrow($exception);
        $redis->expects('getLastError')->andReturnNull();
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
        $redis = m::mock(Redis::class);
        $redis->expects('get')
            ->once()
            ->with('foo')
            ->andThrow($exception);
        $redis->expects('getLastError')->andReturnNull();
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
        $redis = m::mock(Redis::class);
        $redis->expects('exec')
            ->once()
            ->andThrow($exception);
        $redis->expects('getLastError')->andReturn("CROSSSLOT Keys in request don't hash to the same slot");
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

    #[DataProvider('synchronizedServerErrorDispositionProvider')]
    public function testSynchronizedServerErrorDispositionDoesNotReplayCommand(
        string $message,
        bool $sentinel,
        bool $invalid,
    ): void {
        $exception = new RedisException($message);
        $redis = m::mock(Redis::class);
        $redis->expects('set')->once()->with('key', 'value')->andThrow($exception);
        $redis->expects('getLastError')->andReturn($message);
        $connection = new PhpRedisConnectionStub(
            $this->getContainer(),
            $this->getMockedPool(),
            ['sentinel' => ['enabled' => $sentinel]],
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

    public static function synchronizedServerErrorDispositionProvider(): array
    {
        return [
            'standalone READONLY' => ['READONLY replica is read-only', false, false],
            'standalone MASTERDOWN' => ['MASTERDOWN link is down', false, false],
            'standalone LOADING' => ['LOADING data is loading', false, false],
            'standalone OOM' => ['OOM command not allowed', false, false],
            'standalone MISCONF' => ['MISCONF persistence error', false, false],
            'standalone CROSSSLOT' => ["CROSSSLOT Keys in request don't hash to the same slot", false, false],
            'Sentinel READONLY' => ['READONLY replica is read-only', true, true],
            'Sentinel MASTERDOWN' => ['MASTERDOWN link is down', true, true],
            'Sentinel LOADING' => ['LOADING data is loading', true, false],
            'Sentinel non-exact READONLY prefix' => ['READONLY_STATE custom error', true, false],
        ];
    }

    public function testLogWritesToStdoutLogger(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with(LogLevel::ERROR, 'unit');

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(true);
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn($logger);

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($container, $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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

        $connection->getConnection()
            ->shouldReceive('get')
            ->with($key = 'foo')
            ->once()
            ->andReturn($value = 'bar');

        $result = $connection->__call('get', [$key]);

        $this->assertEquals($value, $result);
    }

    public function testGetPreservesDecodedValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $object = (object) ['name' => 'Hypervel'];

        foreach ([['nested' => true], $object, 42] as $index => $value) {
            $key = "key-{$index}";
            $connection->getConnection()
                ->shouldReceive('get')
                ->with($key)
                ->once()
                ->andReturn($value);

            $this->assertSame($value, $connection->__call('get', [$key]));
        }
    }

    public function testMget(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('mGet')
            ->with(['key1', 'key2', 'key3'])
            ->once()
            ->andReturn(['value1', false, 'value3']);

        $result = $connection->__call('mget', [['key1', 'key2', 'key3']]);

        $this->assertEquals(['value1', null, 'value3'], $result);
    }

    public function testMgetPreservesWholeCallFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('mGet')
            ->with(['key1', 'key2'])
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('mget', [['key1', 'key2']]));
    }

    public function testMgetReturnsAnEmptyArrayWithoutCallingRedisForEmptyKeys(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $connection->getConnection()->shouldReceive('mGet')->never();

        $this->assertSame([], $connection->__call('mget', [[]]));
    }

    public function testSet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('set')
            ->with('key', 'value', ['NX', 'EX' => 3600])
            ->once()
            ->andReturn(true);

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
            $connection->getConnection()
                ->shouldReceive('set')
                ->with($key, 'value', ['GET'])
                ->once()
                ->andReturn($result);

            $this->assertSame($result, $connection->__call('set', [$key, 'value', ['GET']]));
        }
    }

    public function testSetnxAcceptsNonStringValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('setNx')
            ->with('key', 123)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('setnx', ['key', 123]);

        $this->assertEquals(1, $result);
    }

    public function testHmgetSingleArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', ['field1', 'field2']]);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmgetMultipleArgs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->__call('hmget', ['hash', 'field1', 'field2']);

        $this->assertEquals(['value1', 'value2'], $result);
    }

    public function testHmgetPreservesWholeCallFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMGet')
            ->with('hash', ['field1', 'field2'])
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('hmget', ['hash', ['field1', 'field2']]));
    }

    public function testHmset(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        $result = $connection->__call('hmset', ['hash', ['field1' => 'value1', 'field2' => 'value2']]);

        $this->assertTrue($result);
    }

    public function testHsetnxAcceptsNonStringValues(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $value = ['nested' => 'value'];

        $connection->getConnection()
            ->shouldReceive('hSetNx')
            ->with('hash', 'field', $value)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('hsetnx', ['hash', 'field', $value]);

        $this->assertEquals(1, $result);
    }

    public function testLrem(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('lRem')
            ->with('list', 'value', 2)
            ->once()
            ->andReturn(1);

        $result = $connection->__call('lrem', ['list', 2, 'value']);

        $this->assertEquals(1, $result);
    }

    public function testBlpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('blPop')
            ->with('list1', 'list2', 10)
            ->once()
            ->andReturn(['list1', 'value']);

        $result = $connection->__call('blpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list1', 'value'], $result);
    }

    public function testBlpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('blPop')
            ->with('list1', 10)
            ->once()
            ->andReturn([]);

        $result = $connection->__call('blpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testBrpopWithResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('brPop')
            ->with('list1', 'list2', 10)
            ->once()
            ->andReturn(['list2', 'value']);

        $result = $connection->__call('brpop', ['list1', 'list2', 10]);

        $this->assertEquals(['list2', 'value'], $result);
    }

    public function testBrpopEmpty(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('brPop')
            ->with('list1', 10)
            ->once()
            ->andReturn([]);

        $result = $connection->__call('brpop', ['list1', 10]);

        $this->assertNull($result);
    }

    public function testSpop(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('sPop')
            ->with('myset', 2)
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->__call('spop', ['myset', 2]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    public function testScan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, '*', 10)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, '*', 10);

        $this->assertEquals([0, ['key1', 'key2']], $result);
    }

    public function testScanWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, 'prefix:*', 20)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, 'prefix:*', 20);

        $this->assertEquals([0, ['key1', 'key2']], $result);
    }

    public function testScanWithEmptyResult(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, '*', 10)
            ->once()
            ->andReturn(false);

        $result = $connection->scan($cursor, '*', 10);

        $this->assertFalse($result);
    }

    public function testZscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('zscan')
            ->with('sortedset', 0, '*', 10)
            ->once()
            ->andReturn(['member1' => 1.0, 'member2' => 2.0]);

        $result = $connection->zscan('sortedset', $cursor, '*', 10);

        $this->assertEquals([0, ['member1' => 1.0, 'member2' => 2.0]], $result);
    }

    public function testHscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('hscan')
            ->with('hash', 0, '*', 10)
            ->once()
            ->andReturn(['field1' => 'value1', 'field2' => 'value2']);

        $result = $connection->hscan('hash', $cursor, '*', 10);

        $this->assertEquals([0, ['field1' => 'value1', 'field2' => 'value2']], $result);
    }

    public function testSscan(): void
    {
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('sscan')
            ->with('set', 0, '*', 10)
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->sscan('set', $cursor, '*', 10);

        $this->assertEquals([0, ['member1', 'member2']], $result);
    }

    public function testEvalsha(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $redisConnection = $connection->getConnection();
        $redisConnection->shouldReceive('script')
            ->with('load', 'script')
            ->once()
            ->andReturn('sha1');

        $redisConnection->shouldReceive('evalSha')
            ->with('sha1', ['key1', 'key2'], 2)
            ->once()
            ->andReturn('result');

        $result = $connection->__call('evalsha', ['script', 2, 'key1', 'key2']);

        $this->assertEquals('result', $result);
    }

    public function testZaddWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', ['NX', 'CH'], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['sortedset', 'NX', 'CH', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZaddPreservesIncrementScoreAndFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', ['INCR'], 1.5, 'member')
            ->once()
            ->andReturn(2.5);
        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', ['XX'], 1.5, 'missing')
            ->once()
            ->andReturnFalse();

        $this->assertSame(2.5, $connection->__call('zadd', ['sortedset', 'INCR', 1.5, 'member']));
        $this->assertFalse($connection->__call('zadd', ['sortedset', 'XX', 1.5, 'missing']));
    }

    public function testZaddWithArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('sortedset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['sortedset', ['member1' => 1.0, 'member2' => 2.0]]);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRangeByScore')
            ->with('sortedset', '1', '5', ['limit' => [0, 10]])
            ->once()
            ->andReturn(['member1', 'member2']);

        $result = $connection->__call('zrangebyscore', ['sortedset', '1', '5', ['limit' => ['offset' => 0, 'count' => 10]]]);

        $this->assertEquals(['member1', 'member2'], $result);
    }

    public function testZrangebyscorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRangeByScore')
            ->with('sortedset', '1', '5', [])
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('zrangebyscore', ['sortedset', '1', '5']));
    }

    public function testFlushdbAsync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('flushdb')
            ->with(true)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('flushdb', ['ASYNC']);

        $this->assertTrue($result);
    }

    public function testFlushdbSync(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('flushdb')
            ->with()
            ->once()
            ->andReturn(true);

        $result = $connection->__call('flushdb', []);

        $this->assertTrue($result);
    }

    public function testExecuteRaw(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('rawCommand')
            ->with('CUSTOM', 'arg1', 'arg2')
            ->once()
            ->andReturn('result');

        $result = $connection->__call('executeRaw', [['CUSTOM', 'arg1', 'arg2']]);

        $this->assertEquals('result', $result);
    }

    public function testZinterstoreWithOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], [1, 2], 'max')
            ->once()
            ->andReturn(3);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2'], ['weights' => [1, 2], 'aggregate' => 'max']]);

        $this->assertEquals(3, $result);
    }

    public function testZinterstorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('zinterstore', ['output', ['set1', 'set2']]));
    }

    public function testZunionstorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zunionstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('zunionstore', ['output', ['set1', 'set2']]));
    }

    public function testZunionstoreSimple(): void
    {
        $connection = $this->mockRedisConnection();
        $connection->shouldTransform(false);

        $connection->getConnection()
            ->shouldReceive('zunionstore')
            ->withAnyArgs()
            ->once()
            ->andReturn(5);

        $result = $connection->__call('zunionstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(5, $result);
    }

    public function testGetTransformsFalseToNull(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('get')
            ->with('key')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('get', ['key']);

        $this->assertNull($result);
    }

    public function testSetWithoutOptions(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('set')
            ->with('key', 'value', null)
            ->once()
            ->andReturn(true);

        $result = $connection->__call('set', ['key', 'value']);

        $this->assertTrue($result);
    }

    public function testSetnxReturnsZeroOnFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('setNx')
            ->with('key', 'value')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertEquals(0, $result);
    }

    public function testHmsetWithAlternatingKeyValuePairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('hMSet')
            ->with('hash', ['field1' => 'value1', 'field2' => 'value2'])
            ->once()
            ->andReturn(true);

        // Laravel style: key, value, key, value
        $result = $connection->__call('hmset', ['hash', 'field1', 'value1', 'field2', 'value2']);

        $this->assertTrue($result);
    }

    public function testZaddWithScoreMemberPairs(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zAdd')
            ->with('zset', [], 1.0, 'member1', 2.0, 'member2')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zadd', ['zset', 1.0, 'member1', 2.0, 'member2']);

        $this->assertEquals(2, $result);
    }

    public function testZrangebyscoreWithListLimitPassesThrough(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRangeByScore')
            ->with('zset', '-inf', '+inf', ['limit' => [5, 20]])
            ->once()
            ->andReturn(['member1']);

        // Already in list format - passes through
        $result = $connection->__call('zrangebyscore', ['zset', '-inf', '+inf', ['limit' => [5, 20]]]);

        $this->assertEquals(['member1'], $result);
    }

    public function testZrevrangebyscoreWithLimitOption(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', ['limit' => [0, 5]])
            ->once()
            ->andReturn(['member2', 'member1']);

        $result = $connection->__call('zrevrangebyscore', ['zset', '+inf', '-inf', ['limit' => ['offset' => 0, 'count' => 5]]]);

        $this->assertEquals(['member2', 'member1'], $result);
    }

    public function testZrevrangebyscorePreservesFailure(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zRevRangeByScore')
            ->with('zset', '+inf', '-inf', [])
            ->once()
            ->andReturnFalse();

        $this->assertFalse($connection->__call('zrevrangebyscore', ['zset', '+inf', '-inf']));
    }

    public function testZinterstoreDefaultsAggregate(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('zinterstore')
            ->with('output', ['set1', 'set2'], null, 'sum')
            ->once()
            ->andReturn(2);

        $result = $connection->__call('zinterstore', ['output', ['set1', 'set2']]);

        $this->assertEquals(2, $result);
    }

    public function testCallWithoutTransformPassesDirectly(): void
    {
        $connection = $this->mockRedisConnection(transform: false);

        // Without transform, get() returns false (not null)
        $connection->getConnection()
            ->shouldReceive('get')
            ->with('key')
            ->once()
            ->andReturn(false);

        $result = $connection->__call('get', ['key']);

        $this->assertFalse($result);
    }

    public function testSerializedReturnsTrueWhenSerializerConfigured(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_PHP);

        $this->assertTrue($connection->serialized());
    }

    public function testSerializedReturnsFalseWhenNoSerializer(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_NONE);

        $this->assertFalse($connection->serialized());
    }

    public function testCompressedReturnsTrueWhenCompressionConfigured(): void
    {
        if (! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis::COMPRESSION_LZF is not defined.');
        }

        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);

        $this->assertTrue($connection->compressed());
    }

    public function testCompressedReturnsFalseWhenNoCompression(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        $this->assertFalse($connection->compressed());
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
        $redis = $connection->getConnection();

        // serialized() check + saving old value
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_PHP);

        // compressed() check
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        // Disable serialization
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        // Restore serialization
        $redis->shouldReceive('setOption')
            ->once()
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
        $redis = $connection->getConnection();

        // serialized() check
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_NONE);

        // compressed() check + saving old value
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);

        // Disable compression
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);

        // Restore compression
        $redis->shouldReceive('setOption')
            ->once()
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
        $redis = $connection->getConnection();

        // serialized() check + saving old value
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_PHP);

        // compressed() check + saving old value
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_LZF);

        // Disable both
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE);

        // Restore both
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);

        $result = $connection->withoutSerializationOrCompression(fn () => 'both-result');

        $this->assertSame('both-result', $result);
    }

    public function testWithoutSerializationOrCompressionSkipsWhenNeitherConfigured(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = $connection->getConnection();

        // Neither serialized nor compressed
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_NONE);
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        // setOption should never be called
        $redis->shouldNotReceive('setOption');

        $result = $connection->withoutSerializationOrCompression(fn () => 'no-change');

        $this->assertSame('no-change', $result);
    }

    public function testWithoutSerializationOrCompressionRestoresOnException(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = $connection->getConnection();

        // serialized() check + saving old value
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_SERIALIZER)
            ->andReturn(Redis::SERIALIZER_PHP);

        // compressed() check
        $redis->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);

        // Disable serialization
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        // Restore serialization even on exception
        $redis->shouldReceive('setOption')
            ->once()
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

    public static function redisClusterHashTagProvider(): array
    {
        return [
            ['plain-key', false],
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

        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $result = $connection->pack(['value1', 'value2']);

        $this->assertSame(['packed1', 'packed2'], $result);
    }

    public function testPackPreservesArrayKeys(): void
    {
        $connection = $this->mockRedisConnection();

        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value1')
            ->once()
            ->andReturn('packed1');
        $connection->getConnection()
            ->shouldReceive('_pack')
            ->with('value2')
            ->once()
            ->andReturn('packed2');

        $result = $connection->pack(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'packed1',
            'key2' => 'packed2',
        ], $result);
    }

    public function testEvalWithShaCacheSucceedsOnFirstTry(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return KEYS[1]';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, ['mykey', 'arg1', 'arg2'], 1)
            ->once()
            ->andReturn('mykey');

        $result = $connection->evalWithShaCache($script, ['mykey'], ['arg1', 'arg2']);

        $this->assertEquals('mykey', $result);
    }

    public function testEvalWithShaCacheThrowsOnNonNoscriptError(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'invalid lua syntax';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        $redisConnection->shouldReceive('evalSha')
            ->with($sha, ['mykey'], 1)
            ->once()
            ->andReturn(false);

        $redisConnection->shouldReceive('getLastError')
            ->once()
            ->andReturn('ERR Error compiling script');

        $this->expectException(LuaScriptException::class);
        $this->expectExceptionMessage('Lua script execution failed: ERR Error compiling script');

        $connection->evalWithShaCache($script, ['mykey']);
    }

    public function testEvalWithShaCacheReturnsLegitimatelyFalseResult(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return false';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        // Script returns false legitimately (no error)
        $redisConnection->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->andReturn(false);

        $redisConnection->shouldReceive('getLastError')
            ->once()
            ->andReturn(null); // No error - script legitimately returned false

        $result = $connection->evalWithShaCache($script);

        $this->assertFalse($result);
    }

    public function testEvalWithShaCacheWorksWithNoKeysOrArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return 42';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->andReturn(42);

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals(42, $result);
    }

    public function testEvalWithShaCacheWorksWithMultipleKeysAndArgs(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return {KEYS[1], KEYS[2], ARGV[1], ARGV[2]}';
        $sha = sha1($script);

        $connection->getConnection()
            ->shouldReceive('evalSha')
            ->with($sha, ['key1', 'key2', 'arg1', 'arg2'], 2)
            ->once()
            ->andReturn(['key1', 'key2', 'arg1', 'arg2']);

        $result = $connection->evalWithShaCache($script, ['key1', 'key2'], ['arg1', 'arg2']);

        $this->assertEquals(['key1', 'key2', 'arg1', 'arg2'], $result);
    }

    public function testEvalWithShaCacheClearsLastErrorBeforeEvalSha(): void
    {
        $connection = $this->mockRedisConnection();
        $script = 'return "ok"';
        $sha = sha1($script);

        $redisConnection = $connection->getConnection();

        // Verify clearLastError is called before evalSha using ordered expectations
        $redisConnection->shouldReceive('clearLastError')
            ->once()
            ->globally()
            ->ordered();

        $redisConnection->shouldReceive('evalSha')
            ->with($sha, [], 0)
            ->once()
            ->globally()
            ->ordered()
            ->andReturn('ok');

        $result = $connection->evalWithShaCache($script);

        $this->assertEquals('ok', $result);
    }

    public function testSpopWithoutCountReturnsSingleElement(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // Without count, phpredis sPop returns a single string
        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('myset')
            ->andReturn('member1');

        $result = $connection->__call('spop', ['myset']);

        $this->assertSame('member1', $result);
    }

    public function testSpopWithCountReturnsArray(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        // With count, phpredis sPop returns an array
        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('myset', 3)
            ->andReturn(['member1', 'member2', 'member3']);

        $result = $connection->__call('spop', ['myset', 3]);

        $this->assertSame(['member1', 'member2', 'member3'], $result);
    }

    public function testSpopWithoutCountReturnsFalseForEmptySet(): void
    {
        $connection = $this->mockRedisConnection(transform: true);

        $connection->getConnection()
            ->shouldReceive('sPop')
            ->once()
            ->with('emptyset')
            ->andReturn(false);

        $result = $connection->__call('spop', ['emptyset']);

        $this->assertFalse($result);
    }

    public function testEvalReordersArguments(): void
    {
        // Can't mock eval() on phpredis — Mockery's proxy falls through to the
        // C extension which tries a real connection. Instead, override callEval
        // to capture the arguments it receives after __call dispatches to it.
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $captured) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return KEYS[1]', 1, 'mykey')
        $result = $connection->__call('eval', ['return KEYS[1]', 1, 'mykey']);

        $this->assertSame('captured', $result);
        $this->assertSame('return KEYS[1]', $captured['script']);
        $this->assertSame(1, $captured['numberOfKeys']);
        $this->assertSame(['mykey'], $captured['arguments']);
    }

    public function testEvalReordersMultipleArguments(): void
    {
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $captured) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return {KEYS[1], ARGV[1]}', 1, 'mykey', 'myarg')
        $result = $connection->__call('eval', ['return {KEYS[1], ARGV[1]}', 1, 'mykey', 'myarg']);

        $this->assertSame('captured', $result);
        $this->assertSame('return {KEYS[1], ARGV[1]}', $captured['script']);
        $this->assertSame(1, $captured['numberOfKeys']);
        $this->assertSame(['mykey', 'myarg'], $captured['arguments']);
    }

    public function testEvalWithNoKeysOrArguments(): void
    {
        $captured = [];
        $connection = new class($this->getContainer(), $this->getMockedPool(), $this->standaloneConfig(), $captured) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
                array $config,
                private array &$captured,
            ) {
                parent::__construct($container, $pool, $config);
            }

            protected function createRedis(array $config): Redis
            {
                return m::mock(Redis::class)->shouldIgnoreMissing();
            }

            protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
            {
                $this->captured = [
                    'script' => $script,
                    'numberOfKeys' => $numberOfKeys,
                    'arguments' => $arguments,
                ];

                return 'captured';
            }
        };

        $connection->shouldTransform(true);

        // User calls: eval('return 42', 0)
        $result = $connection->__call('eval', ['return 42', 0]);

        $this->assertSame('captured', $result);
        $this->assertSame('return 42', $captured['script']);
        $this->assertSame(0, $captured['numberOfKeys']);
        $this->assertSame([], $captured['arguments']);
    }

    #[DataProvider('unsupportedSubscriptionProvider')]
    public function testSubscriptionsThrowOnPooledConnection(string $command): void
    {
        $connection = $this->mockRedisConnection();

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage("Cannot call {$command}() on a pooled RedisConnection.");

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
        $pool->expects('discard')->with(m::type(RedisConnection::class));
        $redis = m::mock(Redis::class);
        $redis->expects('watch')->with('key')->andReturnTrue();
        $redis->expects('reset')->never();
        $redis->expects('getMode')->andReturn(Redis::ATOMIC);
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

    #[DataProvider('hostFormattingProvider')]
    public function testFormatHost(
        array $config,
        ?string $expected,
        ?string $exceptionMessage,
    ): void {
        $connection = new class extends PhpRedisConnectionStub {
            public function formatHostForTest(array $config): string
            {
                return $this->formatHost($config);
            }
        };

        if ($exceptionMessage !== null) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($exceptionMessage);

            $connection->formatHostForTest($config);

            return;
        }

        $this->assertSame($expected, $connection->formatHostForTest($config));
    }

    public static function hostFormattingProvider(): array
    {
        return [
            'empty host' => [
                ['host' => ''],
                null,
                'Redis host must be a non-empty string.',
            ],
            'matching scheme' => [
                ['host' => 'tls://redis.test', 'scheme' => 'TLS'],
                'tls://redis.test',
                null,
            ],
            'mismatched scheme' => [
                ['host' => 'tls://redis.test', 'scheme' => 'tcp'],
                null,
                'must match the scheme option',
            ],
        ];
    }

    public function testReconnectSetsSerializerOption(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['serializer' => Redis::SERIALIZER_PHP]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_PREFIX, 'myapp:');

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['prefix' => 'myapp:']]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->expects('setOption')
            ->with(Redis::OPT_PACK_IGNORE_NUMBERS, true)
            ->andReturnTrue();

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['pack_ignore_numbers' => true]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')->andReturnTrue();

        $this->expectException(InvalidRedisOptionException::class);
        $this->expectExceptionMessage('The redis option `pack_ignore_numbers` requires PhpRedis 6.2 or later.');

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['pack_ignore_numbers' => true]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_READ_TIMEOUT, 5.0);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_MAX_RETRIES, 4);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_CONSTANT);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_BACKOFF_BASE, 200);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_BACKOFF_CAP, 2000);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['read_timeout' => 5.0, 'max_retries' => 4, 'backoff_algorithm' => 'constant', 'backoff_base' => 200, 'backoff_cap' => 2000]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['read_timeout' => 0.0]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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

    public function testReconnectSetsNumericBackoffAlgorithmAsIs(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DEFAULT);

        $redis->shouldReceive('setOption')->andReturnTrue();

        new class($this->getContainer(), $pool, $this->standaloneConfig(['backoff_algorithm' => Redis::BACKOFF_ALGORITHM_DEFAULT]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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

    public function testReconnectThrowsOnUnknownBackoffAlgorithm(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $this->expectException(InvalidRedisOptionException::class);
        $this->expectExceptionMessage('Algorithm [bogus] is not a valid PhpRedis backoff algorithm.');

        $redis->shouldReceive('setOption')->andReturnTrue();

        new class($this->getContainer(), $pool, $this->standaloneConfig(['backoff_algorithm' => 'bogus']), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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

    public function testReconnectThrowsOnUnknownOption(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);

        $this->expectException(InvalidRedisOptionException::class);
        $this->expectExceptionMessage('The redis option key `bogus` is invalid.');

        $redis->shouldReceive('setOption')->andReturnTrue();

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => ['bogus' => 'value']]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);

        // Numeric keys bypass the match statement and pass directly
        $redis->shouldReceive('setOption')
            ->once()
            ->with(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['options' => [Redis::OPT_SERIALIZER => Redis::SERIALIZER_JSON]]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('auth')
            ->once()
            ->with('secret');

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['password' => 'secret']), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $redis = m::mock(Redis::class);
        $redis->shouldNotReceive('auth');

        $this->expectDefaultConnectionOptions($redis);

        new class($this->getContainer(), $pool, $this->standaloneConfig(['password' => '']), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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

    public function testCloseNullsConnection(): void
    {
        $connection = $this->mockRedisConnection();

        // getActiveConnection() lazily creates the connection
        $connection->getActiveConnection();
        $this->assertNotNull($connection->client());

        $result = $connection->close();

        $this->assertTrue($result);
        $this->assertNull($connection->client());
    }

    public function testCloseInvokesNativeRedisClose(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('close')->once()->andReturnTrue();

        $connection->setActiveConnection($redis);
        $this->assertNotNull($connection->client());

        $result = $connection->close();

        $this->assertTrue($result);
        $this->assertNull($connection->client());
    }

    public function testCloseSwallowsExceptionFromNativeRedisClose(): void
    {
        $connection = $this->mockRedisConnection();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('close')
            ->once()
            ->andThrow(new RedisException('connection already closed'));

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
        $clusterMock = m::mock(RedisCluster::class);
        $clusterMock->shouldReceive('getMode')->andReturn(Redis::ATOMIC);
        $clusterMock->shouldReceive('setNx')
            ->once()
            ->with('key', 'value')
            ->andReturn(true);

        $connection->setActiveConnection($clusterMock);

        $result = $connection->__call('setnx', ['key', 'value']);

        $this->assertSame(1, $result);
    }

    public function testReconnectClearsInvalidState(): void
    {
        $pool = $this->getMockedPool();
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('select')->andReturn(true);
        $redis->shouldNotReceive('isConnected');

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(['database' => 1]), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(maxIdleTime: 60.0));

        $redis = m::mock(Redis::class);

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')->andReturn(new PoolOption(maxIdleTime: 60.0));
        $redis = m::mock(Redis::class);

        $redis->shouldReceive('setOption')->andReturnTrue();

        $connection = new class($this->getContainer(), $pool, $this->standaloneConfig(), $redis) extends PhpRedisConnection {
            public function __construct(
                ContainerContract $container,
                PoolInterface $pool,
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
        $connection = $this->mockRedisConnection(transform: true);
        $cursor = 0;

        $connection->getConnection()
            ->shouldReceive('scan')
            ->with(0, 'prefix:*', 20)
            ->once()
            ->andReturn(['key1', 'key2']);

        $result = $connection->scan($cursor, ['match' => 'prefix:*', 'count' => 20]);

        $this->assertEquals([0, ['key1', 'key2']], $result);
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
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1.0,
                'heartbeat_timeout' => 1.0,
                'max_idle_time' => 60.0,
                'max_lifetime' => -1.0,
            ],
        ];
    }

    /**
     * Expect the default connection-level phpredis options.
     */
    protected function expectDefaultConnectionOptions(Redis $redis): void
    {
        $redis->expects('setOption')->with(Redis::OPT_MAX_RETRIES, 3)->andReturnTrue();
        $redis->expects('setOption')
            ->with(Redis::OPT_BACKOFF_ALGORITHM, Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER)
            ->andReturnTrue();
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_BASE, 100)->andReturnTrue();
        $redis->expects('setOption')->with(Redis::OPT_BACKOFF_CAP, 1000)->andReturnTrue();
    }

    /**
     * Create a Redis connection test double.
     */
    protected function mockRedisConnection(?ContainerContract $container = null, ?PoolInterface $pool = null, array $options = [], bool $transform = false): RedisConnection
    {
        $connection = new PhpRedisConnectionStub(
            $container ?? $this->getContainer(),
            $pool ?? $this->getMockedPool(),
            $this->standaloneConfig($options)
        );

        if ($transform) {
            $connection->shouldTransform(true);
        }

        return $connection;
    }

    protected function getMockedPool(): PoolInterface
    {
        $pool = m::mock(PoolInterface::class);
        $pool->shouldReceive('getOption')
            ->andReturn(new PoolOption);

        return $pool;
    }

    /**
     * Capture cancellation raised while the current coroutine is canceled.
     */
    protected function captureCancellationAtBoundary(Closure $callback): Throwable
    {
        $blocker = new SwooleChannel(1);
        $captured = null;
        $coroutineId = Coroutine::create(function () use ($blocker, $callback, &$captured): void {
            try {
                $blocker->pop();
            } catch (CanceledException) {
                try {
                    $callback();
                } catch (Throwable $exception) {
                    $captured = $exception;
                }
            }
        });

        $this->assertTrue(EngineCoroutine::cancelById($coroutineId, throwException: true));
        $this->assertInstanceOf(Throwable::class, $captured);

        return $captured;
    }

    protected function getContainer(array $definitions = []): Container
    {
        $container = new Container;

        foreach ($definitions as $abstract => $concrete) {
            $container->singleton($abstract, $concrete);
        }

        return $container;
    }
}
