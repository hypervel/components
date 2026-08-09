<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Error;
use Exception;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Pool\PoolOptionInterface;
use Hypervel\Contracts\Session\Session;
use Hypervel\Http\Request;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;

class RedisIntegrationTest extends SentryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(RedisFeature::class)->detectSessionKeyOnConsole = true;
    }

    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.tracing.redis_commands' => true,
        'sentry.tracing.redis_origin' => false,
        'sentry.features' => [
            RedisFeature::class,
        ],
    ];

    public function testFeatureIsApplicableWhenRedisCommandsTracingIsEnabled(): void
    {
        $feature = $this->app->make(RedisFeature::class);

        $this->assertTrue($feature->isApplicable());
    }

    public function testFeatureEnablesRedisEventsForFuturePools(): void
    {
        $this->app->make('config')->set('database.redis.observed', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $this->assertTrue(
            $this->app->make(RedisConfig::class)
                ->connectionConfig('observed')['event']['enable'],
        );
    }

    public function testFeatureIsNotApplicableWhenRedisCommandsTracingIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => false,
            'sentry.features' => [
                RedisFeature::class,
            ],
        ]);

        $feature = $this->app->make(RedisFeature::class);

        $this->assertFalse($feature->isApplicable());
    }

    public function testRedisCommandCreatesSpanWhenParentSpanExists(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals('GET test-key', $redisSpan->getDescription());

        $spanData = $redisSpan->getData();
        $this->assertEquals('redis', $spanData['db.system']);
        $this->assertEquals('GET test-key', $spanData['db.statement']);
        $this->assertEquals('default', $spanData['db.redis.connection']);
        $this->assertEquals(0.005, $spanData['duration']);
        $this->assertArrayNotHasKey('db.redis.parameters', $spanData);
    }

    public function testRedisCommandWithSessionKeyReplacesWithPlaceholder(): void
    {
        $this->setupMocks();
        $this->startSession();
        $sessionId = $this->app['session']->getId();
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', [$sessionId], 0.005, $connection);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];

        $this->assertEquals('GET {sessionKey}', $redisSpan->getDescription());
        $this->assertEquals('GET {sessionKey}', $redisSpan->getData()['db.statement']);
    }

    public function testRedisParametersRequirePiiConsentAndRedactSessionKey(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $this->app->make(RedisFeature::class)->detectSessionKeyOnConsole = true;
        $this->setupMocks();
        $this->startSession();
        $sessionId = $this->app['session']->getId();
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $dispatcher->dispatch(new CommandExecuted('SET', [$sessionId, 'value'], 0.005, $connection));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertSame(['{sessionKey}', 'value'], $redisSpan->getData()['db.redis.parameters']);
    }

    public function testRedisKeyZeroIsPreservedInDescription(): void
    {
        $this->setupMocks();
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $dispatcher->dispatch(new CommandExecuted('GET', ['0'], 0.005, $connection));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertSame('GET 0', $redisSpan->getDescription());
        $this->assertSame('GET 0', $redisSpan->getData()['db.statement']);
    }

    public function testRedisSessionKeyUsesCurrentRequestCookieBeforeResolvingStore(): void
    {
        $this->setupMocks();
        $this->assertFalse($this->app->resolved('session.store'));
        $cookieName = $this->app->make('config')->string('session.cookie');
        $request = Request::create('/');
        $request->cookies->set($cookieName, 'cookie-session');
        RequestContext::set($request);
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $dispatcher->dispatch(new CommandExecuted('GET', ['cookie-session'], 0.005, $connection));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertSame('GET {sessionKey}', $redisSpan->getDescription());
        $this->assertFalse($this->app->resolved('session.store'));
    }

    public function testRedisSessionKeyFallbackIsReentrySafe(): void
    {
        $this->setupMocks();
        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $session = m::mock(Session::class);
        $session->shouldReceive('getId')->once()->andReturn('outer-key');
        $this->app->bind('session.store', function () use ($dispatcher, $connection, $session): Session {
            $dispatcher->dispatch(new CommandExecuted('GET', ['inner-key'], 0.005, $connection));

            return $session;
        });
        $transaction = $this->startTransaction();

        $dispatcher->dispatch(new CommandExecuted('GET', ['outer-key'], 0.005, $connection));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(3, $spans);
        $this->assertSame('GET inner-key', $spans[1]->getDescription());
        $this->assertSame('GET {sessionKey}', $spans[2]->getDescription());
    }

    public function testRedisSessionResolutionThrowableDoesNotBreakCommandTracing(): void
    {
        $this->setupMocks();
        $this->app->bind('session.store', static fn (): never => throw new Error('Session resolution failed.'));
        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $dispatcher->dispatch(new CommandExecuted('GET', ['test-key'], 0.005, $connection));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertSame('GET test-key', $redisSpan->getDescription());
    }

    public function testRedisCommandWithoutParentSpanDoesNotCreateSpan(): void
    {
        $this->setupMocks();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection);

        $dispatcher->dispatch($event);

        $this->assertEmpty($this->getCapturedSentryEvents());
    }

    public function testRedisCommandWithUnsampledParentSpanDoesNotCreateSpan(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();
        $transaction->setSampled(false);

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(1, $spans);
    }

    public function testRedisCommandWithMultilineKeyUsesEmptyDescription(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('SET', ["multi\nline\nkey", 'value'], 0.005, $connection);

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

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('DEL', [123], 0.005, $connection);

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

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection);

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

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('cache');
        $event = new CommandExecuted('SET', ['cache-key', 'value'], 0.010, $connection);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $redisSpan = $spans[1];
        $spanData = $redisSpan->getData();

        $this->assertEquals('cache', $spanData['db.redis.connection']);
        $this->assertEquals(1, $spanData['db.redis.database_index']);
        $this->assertEquals(0.010, $spanData['duration']);
    }

    public function testRedisFeatureWorksAfterReplacingStaleGlobalHub(): void
    {
        $staleHub = m::mock(HubInterface::class);
        SentrySdk::setCurrentHub($staleHub);

        $this->refreshApplication();
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $event = new CommandExecuted('GET', ['test-key'], 0.005, $connection);

        $dispatcher->dispatch($event);

        $this->assertNotSame($staleHub, SentrySdk::getCurrentHub());
        $this->assertSame($this->app->make(HubInterface::class), SentrySdk::getCurrentHub());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);
    }

    public function testFailedRedisCommandCreatesErrorSpanWithTime(): void
    {
        $this->setupMocks('cache', 2);

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('cache');
        $exception = new Exception('Connection refused');
        $event = new CommandFailed('GET', ['test-key'], $exception, $connection, 0.005);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals('GET test-key', $redisSpan->getDescription());
        $this->assertEquals(\Sentry\Tracing\SpanStatus::internalError(), $redisSpan->getStatus());

        $spanData = $redisSpan->getData();
        $this->assertEquals('redis', $spanData['db.system']);
        $this->assertEquals('Connection refused', $spanData['db.redis.error']);
        $this->assertEquals('cache', $spanData['db.redis.connection']);
        $this->assertEquals(2, $spanData['db.redis.database_index']);
        $this->assertEquals('cache', $spanData['db.redis.pool.name']);
        $this->assertEquals(10, $spanData['db.redis.pool.max']);
        $this->assertEquals(0.005, $spanData['duration']);
    }

    public function testFailedRedisCommandCreatesErrorSpanWithoutTime(): void
    {
        $this->setupMocks();

        $transaction = $this->startTransaction();

        $dispatcher = $this->app->make(Dispatcher::class);
        $connection = $this->createRedisConnection('default');
        $exception = new Exception('Connection refused');
        $event = new CommandFailed('GET', ['test-key'], $exception, $connection);

        $dispatcher->dispatch($event);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals(\Sentry\Tracing\SpanStatus::internalError(), $redisSpan->getStatus());
        $this->assertArrayNotHasKey('duration', $redisSpan->getData());
    }

    private function setupMocks(string $connectionName = 'default', int $database = 0): void
    {
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

        $config = $this->app->make('config');
        $config->set("database.redis.{$connectionName}", [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => $database,
        ]);
    }

    private function createRedisConnection(string $name): RedisConnection
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn($name);

        return $connection;
    }
}
