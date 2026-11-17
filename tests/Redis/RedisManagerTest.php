<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Redis\RedisSentinelFactory;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Tests for RedisManager — the named connection manager.
 */
class RedisManagerTest extends TestCase
{
    use VerifiesDoubles;

    public function testConnectionReturnsRedisProxy(): void
    {
        $manager = $this->createManager(['default']);

        $connection = $manager->connection('default');

        $this->assertInstanceOf(RedisProxy::class, $connection);
    }

    public function testConnectionReturnsSameInstanceOnRepeatedCalls(): void
    {
        $manager = $this->createManager(['default']);

        $first = $manager->connection('default');
        $second = $manager->connection('default');

        $this->assertSame($first, $second);
    }

    public function testConnectionThrowsForUnconfiguredConnection(): void
    {
        $manager = $this->createManager(['default']);

        $this->expectException(InvalidArgumentException::class);

        $manager->connection('nonexistent');
    }

    public function testConnectionDefaultsToDefault(): void
    {
        $manager = $this->createManager(['default']);

        $withNull = $manager->connection(null);
        $withoutArg = $manager->connection();
        $withEmptyString = $manager->connection('');

        $this->assertSame($withNull, $withoutArg);
        $this->assertSame($withNull, $withEmptyString);
        $this->assertSame('default', $withNull->getName());
    }

    public function testPurgeAcceptsUnitEnum(): void
    {
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')->with('default');
        $manager = $this->createManager(['default'], poolManager: $poolManager);
        $manager->connection('default');

        $this->assertCount(1, $manager->connections());

        $manager->purge(FakeRedisConnectionName::Default);

        $this->assertCount(0, $manager->connections());
    }

    public function testIntegerBackedEnumConnectionNameIsNormalizedForResolutionAndPurge(): void
    {
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')->with('0');

        $manager = $this->createManager(['0'], poolManager: $poolManager);
        $connection = $manager->connection(RedisConnectionName::Zero);

        $this->assertSame('0', $connection->getName());
        $this->assertSame($connection, $manager->connections()['0']);

        $manager->purge(RedisConnectionName::Zero);

        $this->assertSame([], $manager->connections());
        $this->assertFalse(CoroutineContext::has($this->contextKey($connection)));
    }

    public function testPurgeClearsProxyContextAndPool(): void
    {
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')->with('default');

        $manager = $this->createManager(['default'], poolManager: $poolManager);

        $first = $manager->connection('default');

        $manager->purge('');

        // Context should be cleared
        $this->assertFalse(CoroutineContext::has($this->contextKey($first)));

        // Next connection() should return a new instance
        $second = $manager->connection('default');
        $this->assertNotSame($first, $second);
    }

    public function testPurgeDiscardsContextPinnedConnection(): void
    {
        $pinnedConnection = Double::for(PhpRedisConnection::class)->strict();
        $pinnedConnection->expects('discard');

        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')->with('default');
        $manager = $this->createManager(['default'], poolManager: $poolManager);
        $contextKey = $this->contextKey($manager->connection('default'));
        CoroutineContext::set($contextKey, $pinnedConnection);

        $manager->purge('default');

        $this->assertFalse(CoroutineContext::has($contextKey));
    }

    public function testReleaseConnectionsExhaustsProxiesAndPreservesFirstFailure(): void
    {
        $firstException = new RuntimeException('First release failed.');
        $first = Double::for(PhpRedisConnection::class)->strict();
        $first->expects('release')->throws($firstException);
        $second = Double::for(PhpRedisConnection::class)->strict();
        $second->expects('release');
        $manager = $this->createManager(['first', 'second']);
        CoroutineContext::set($this->contextKey($manager->connection('first')), $first);
        CoroutineContext::set($this->contextKey($manager->connection('second')), $second);

        try {
            $manager->releaseConnections();
            $this->fail('Expected the first release failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($firstException, $throwable);
        }
    }

    public function testReleaseConnectionsExhaustsProxiesAndPreservesFirstCancellation(): void
    {
        $ordinaryFailure = new RuntimeException('First release failed.');
        $cancellation = new CanceledException('Second release canceled.');
        $laterCancellation = new CanceledException('Third release canceled.');
        $first = Double::for(PhpRedisConnection::class)->strict();
        $first->expects('release')->throws($ordinaryFailure);
        $second = Double::for(PhpRedisConnection::class)->strict();
        $second->expects('release')->throws($cancellation);
        $third = Double::for(PhpRedisConnection::class)->strict();
        $third->expects('release')->throws($laterCancellation);
        $manager = $this->createManager(['first', 'second', 'third']);
        CoroutineContext::set($this->contextKey($manager->connection('first')), $first);
        CoroutineContext::set($this->contextKey($manager->connection('second')), $second);
        CoroutineContext::set($this->contextKey($manager->connection('third')), $third);

        try {
            $manager->releaseConnections();
            $this->fail('Expected the first cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }
    }

    public function testDiscardConnectionsExhaustsEveryCreatedProxy(): void
    {
        $first = Double::for(PhpRedisConnection::class)->strict();
        $first->expects('discard');
        $second = Double::for(PhpRedisConnection::class)->strict();
        $second->expects('discard');
        $manager = $this->createManager(['first', 'second']);
        CoroutineContext::set($this->contextKey($manager->connection('first')), $first);
        CoroutineContext::set($this->contextKey($manager->connection('second')), $second);

        $manager->discardConnections();
    }

    public function testPurgeClosesPoolAfterDiscardFailureAndPreservesFirstFailure(): void
    {
        $discardException = new RuntimeException('Discard failed.');
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->expects('discard')->throws($discardException);
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')
            ->with('alias')
            ->throws(new RuntimeException('Purge failed.'));
        $manager = $this->createManager(['alias'], poolManager: $poolManager);
        CoroutineContext::set($this->contextKey($manager->connection('alias')), $connection);

        try {
            $manager->purge('alias');
            $this->fail('Expected the discard failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($discardException, $throwable);
        }

        $this->assertSame([], $manager->connections());
    }

    public function testPurgeClosesPoolAndLetsCancellationSupersedeDiscardFailure(): void
    {
        $cancellation = new CanceledException('Purge canceled.');
        $connection = Double::for(PhpRedisConnection::class)->strict();
        $connection->expects('discard')->throws(new RuntimeException('Discard failed.'));
        $poolManager = Double::for(new PoolManager(new Container))->passthru();
        $poolManager->expects('purge')
            ->with('alias')
            ->throws($cancellation);
        $manager = $this->createManager(['alias'], poolManager: $poolManager);
        CoroutineContext::set($this->contextKey($manager->connection('alias')), $connection);

        try {
            $manager->purge('alias');
            $this->fail('Expected the cancellation to propagate.');
        } catch (Throwable $throwable) {
            $this->assertSame($cancellation, $throwable);
        }

        $this->assertSame([], $manager->connections());
    }

    public function testConnectorDriverExtensionsAreIntentionallyUnavailable(): void
    {
        // REMOVED: Hypervel has one phpredis pooled transport rather than switchable connector drivers.
        $manager = $this->createManager(['default']);

        $this->assertFalse(method_exists($manager, 'extend'));
        $this->assertFalse(method_exists($manager, 'forgetExtension'));
        $this->assertFalse(method_exists($manager, 'setDriver'));
    }

    public function testEnableEventsRefreshesOnlyExistingPoolsWithDisabledEvents(): void
    {
        $app = Double::for(ContainerContract::class, override: true)->strict();
        $disabledPool = Double::for(RedisPool::class)->strict();
        $disabledPool->expects('getConfig')->returns(['events' => false]);
        $enabledPool = Double::for(RedisPool::class)->strict();
        $enabledPool->expects('getConfig')->returns(['events' => true]);

        $operations = [];
        $config = Double::for(RedisConfig::class)->strict();
        $config->expects('enableEvents')->resolves(static function () use (&$operations): void {
            $operations[] = 'enableEvents';
        });

        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('pool')->never();
        $poolManager->expects('getPools')->resolves(static function () use (&$operations, $disabledPool, $enabledPool): array {
            $operations[] = 'getPools';

            return ['disabled' => $disabledPool, 'enabled' => $enabledPool];
        });
        $poolManager->expects('purge')->with('disabled')->resolves(static function () use (&$operations): void {
            $operations[] = 'purge';
        });

        $manager = new RedisManager(
            $app->instance(),
            $poolManager,
            $config,
            Double::for(RedisSentinelFactory::class)->strict(),
        );

        $manager->enableEvents();

        $this->assertSame(['enableEvents', 'getPools', 'purge'], $operations);
    }

    public function testDisableEventsRefreshesOnlyExistingPoolsWithEnabledEvents(): void
    {
        $app = Double::for(ContainerContract::class, override: true)->strict();
        $enabledPool = Double::for(RedisPool::class)->strict();
        $enabledPool->expects('getConfig')->returns(['events' => true]);
        $disabledPool = Double::for(RedisPool::class)->strict();
        $disabledPool->expects('getConfig')->returns(['events' => false]);

        $operations = [];
        $config = Double::for(RedisConfig::class)->strict();
        $config->expects('disableEvents')->resolves(static function () use (&$operations): void {
            $operations[] = 'disableEvents';
        });

        $poolManager = Double::for(PoolManager::class)->strict();
        $poolManager->expects('pool')->never();
        $poolManager->expects('getPools')->resolves(static function () use (&$operations, $disabledPool, $enabledPool): array {
            $operations[] = 'getPools';

            return ['enabled' => $enabledPool, 'disabled' => $disabledPool];
        });
        $poolManager->expects('purge')->with('enabled')->resolves(static function () use (&$operations): void {
            $operations[] = 'purge';
        });

        $manager = new RedisManager(
            $app->instance(),
            $poolManager,
            $config,
            Double::for(RedisSentinelFactory::class)->strict(),
        );

        $manager->disableEvents();

        $this->assertSame(['disableEvents', 'getPools', 'purge'], $operations);
    }

    public function testCallDelegatesToDefaultConnection(): void
    {
        $manager = $this->createManager(['default']);

        // connection() returns a RedisProxy. We can verify __call delegation
        // by checking that the proxy's getName() is returned via the manager.
        $this->assertSame('default', $manager->getName());
    }

    public function testListenRegistersCommandExecutedListener(): void
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('listen')
            ->with(CommandExecuted::class, Argument::type('Closure'));

        $app = Double::for(ContainerContract::class, override: true)->strict();
        $app->allows('bound')->with('events')->returns(true);
        $app->allows('make')->with('events')->returns($dispatcher);

        $manager = new RedisManager(
            $app->instance(),
            Double::for(PoolManager::class)->strict(),
            $this->createRedisConfig(['default']),
            Double::for(RedisSentinelFactory::class)->strict(),
        );

        $manager->listen(function () {});
    }

    public function testListenForFailuresRegistersCommandFailedListener(): void
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('listen')
            ->with(CommandFailed::class, Argument::type('Closure'));

        $app = Double::for(ContainerContract::class, override: true)->strict();
        $app->allows('bound')->with('events')->returns(true);
        $app->allows('make')->with('events')->returns($dispatcher);

        $manager = new RedisManager(
            $app->instance(),
            Double::for(PoolManager::class)->strict(),
            $this->createRedisConfig(['default']),
            Double::for(RedisSentinelFactory::class)->strict(),
        );

        $manager->listenForFailures(function () {});
    }

    public function testConnectionsReturnsAllCachedProxies(): void
    {
        $manager = $this->createManager(['default', 'cache']);

        $this->assertEmpty($manager->connections());

        $default = $manager->connection('default');
        $cache = $manager->connection('cache');

        $connections = $manager->connections();

        $this->assertCount(2, $connections);
        $this->assertSame($default, $connections['default']);
        $this->assertSame($cache, $connections['cache']);
    }

    /**
     * Create a RedisManager with mocked dependencies.
     *
     * @param list<string> $configuredConnections Connection names that config considers valid
     */
    private function createManager(
        array $configuredConnections,
        ?PoolManager $poolManager = null
    ): RedisManager {
        $app = Double::for(ContainerContract::class, override: true)->strict();
        $poolManager ??= new PoolManager(new Container);
        $config = $this->createRedisConfig($configuredConnections);

        return new RedisManager(
            $app->instance(),
            $poolManager,
            $config,
            Double::for(RedisSentinelFactory::class)->strict(),
        );
    }

    /**
     * Get the coroutine context key of the given proxy's pinned connection.
     */
    private function contextKey(RedisProxy $redis): string
    {
        return (fn (): string => $this->getContextKey())->call($redis);
    }

    /**
     * Create Redis configuration for the given connection names.
     *
     * @param list<string> $validNames
     */
    private function createRedisConfig(array $validNames): RedisConfig
    {
        $configData = ['options' => []];
        foreach ($validNames as $name) {
            $configData[$name] = [
                'host' => 'localhost',
                'port' => 6379,
                'database' => 0,
                'options' => [],
            ];
        }

        $repository = new Repository(['database' => ['redis' => $configData]]);

        return new RedisConfig($repository);
    }
}

enum RedisConnectionName: int
{
    case Zero = 0;
}

enum FakeRedisConnectionName: string
{
    case Default = 'default';
}
