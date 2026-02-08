<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Config\Repository;
use Hyperf\Contract\PoolOptionInterface;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Session\SessionManager;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

/**
 * @internal
 * @coversNothing
 */
class RedisFeatureTest extends SentryTestCase
{
    use RunTestsInCoroutine;

    protected array $defaultSetupConfig = [
        'sentry.tracing.redis_commands' => true,
        'sentry.tracing.redis_origin' => false, // Disable origin tracking to avoid complex dependencies
        'sentry.features' => [
            RedisFeature::class,
        ],
    ];

    public function testFeatureIsApplicableWhenRedisCommandsTracingIsEnabled(): void
    {
        $feature = $this->app->get(RedisFeature::class);

        $this->assertTrue($feature->isApplicable());
    }

    public function testFeatureIsNotApplicableWhenRedisCommandsTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => false,
        ]);

        $feature = $this->app->get(RedisFeature::class);

        $this->assertFalse($feature->isApplicable());
    }

    public function testRedisCommandCreatesSpanWhenParentSpanExists(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans); // Transaction + Redis span

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals('GET test-key', $redisSpan->getDescription());

        $spanData = $redisSpan->getData();
        $this->assertEquals('redis', $spanData['db.system']);
        $this->assertEquals('GET test-key', $spanData['db.statement']);
        $this->assertEquals('default', $spanData['db.redis.connection']);
        $this->assertEquals(5.0, $spanData['duration']); // 0.005s * 1000
    }

    public function testRedisCommandWithSessionKeyReplacesWithPlaceholder(): void
    {
        $this->setupMocks();
        $this->startSession();
        $this->app->get(SessionManager::class)->setId($sessionId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', [$sessionId], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];

        $this->assertEquals('GET {sessionKey}', $redisSpan->getDescription());
        $this->assertEquals('GET {sessionKey}', $redisSpan->getData()['db.statement']);
    }

    public function testRedisCommandWithoutParentSpanDoesNotCreateSpan(): void
    {
        $this->setupMocks();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        // Should not create any events since there's no parent span
        $this->assertEmpty($this->getCapturedSentryEvents());
    }

    public function testRedisCommandWithUnsampledParentSpanDoesNotCreateSpan(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();
        $transaction->setSampled(false);

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(1, $spans); // Only transaction, no Redis span
    }

    public function testRedisCommandWithMultilineKeyUsesEmptyDescription(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('SET', ["multi\nline\nkey", 'value'], 0.005, $connection, 'default', 'OK', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];

        $this->assertEquals('SET', $redisSpan->getDescription());
        $this->assertEquals('SET', $redisSpan->getData()['db.statement']);
    }

    public function testRedisCommandWithNonStringKeyUsesEmptyKey(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('DEL', [123], 0.005, $connection, 'default', 1, null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];

        $this->assertEquals('DEL', $redisSpan->getDescription());
        $this->assertEquals('DEL', $redisSpan->getData()['db.statement']);
    }

    public function testRedisCommandIncludesPoolInformation(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];
        $spanData = $redisSpan->getData();

        $this->assertEquals('default', $spanData['db.redis.pool.name']);
        $this->assertEquals(10, $spanData['db.redis.pool.max']);
        $this->assertEquals(60.0, $spanData['db.redis.pool.max_idle_time']);
        $this->assertEquals(5, $spanData['db.redis.pool.idle']);
        $this->assertEquals(2, $spanData['db.redis.pool.using']);
    }

    public function testRedisCommandWithDifferentConfiguration(): void
    {
        $this->setupMocks('cache', 1);

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('cache');
        $event = new CommandExecuted('SET', ['cache-key', 'value'], 0.010, $connection, 'cache', 'OK', null);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];
        $spanData = $redisSpan->getData();

        $this->assertEquals('cache', $spanData['db.redis.connection']);
        $this->assertEquals(1, $spanData['db.redis.database_index']);
        $this->assertEquals(10.0, $spanData['duration']);
    }

    public function testRedisFeatureWorksAfterReplacingStaleGlobalHub(): void
    {
        $staleHub = m::mock(HubInterface::class);
        SentrySdk::setCurrentHub($staleHub);

        $this->refreshApplication();
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->get(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection, 'default', 'value', null);

        $dispatcher->dispatch($event);

        $this->assertNotSame($staleHub, SentrySdk::getCurrentHub());
        $this->assertSame($this->app->get(HubInterface::class), SentrySdk::getCurrentHub());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);
    }

    private function setupMocks(string $connectionName = 'default', int $database = 0): void
    {
        // Mock PoolFactory
        $poolOption = m::mock(PoolOptionInterface::class);
        $poolOption->shouldReceive('getMaxConnections')->andReturn(10);
        $poolOption->shouldReceive('getMaxIdleTime')->andReturn(60.0);

        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('getOption')->andReturn($poolOption);
        $pool->shouldReceive('getConnectionsInChannel')->andReturn(5);
        $pool->shouldReceive('getCurrentConnections')->andReturn(2);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with($connectionName)->andReturn($pool);

        $this->app->instance(PoolFactory::class, $poolFactory);

        // Mock Redis config
        $config = $this->app->get(Repository::class);
        $config->set("database.redis.{$connectionName}", [
            'host' => '127.0.0.1',
            'port' => 6379,
            'db' => $database,
        ]);
    }

    private function createRedisConnection(string $name): RedisConnection
    {
        return m::mock(RedisConnection::class);
    }
}
