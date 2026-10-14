<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Error;
use Exception;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Repository;
use Hypervel\ConnectionPool\PoolOptions;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Session\Session;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisManager;
use Hypervel\Sentry\Features\CacheIntegration;
use Hypervel\Session\NullSessionHandler;
use Hypervel\Session\Store as SessionStore;
use Hypervel\Support\Facades\Cache;
use Hypervel\Tests\Sentry\TestCase;
use Mockery as m;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

class CacheIntegrationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.features' => [
            CacheIntegration::class,
        ],
        // Cache tests use the array store, so tracing Redis commands only affects the Redis tests
        'sentry.tracing.redis_commands' => true,
    ];

    /**
     * Set up the test environment.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure that session keys can be detected in the tests that are running from the console
        $this->app->make(CacheIntegration::class)->detectSessionKeyOnConsole = true;
    }

    public function testCacheBreadcrumbForWriteAndHitIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::get('foo');

        $this->assertEquals("Read: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForWriteAndForgetIsRecorded(): void
    {
        Cache::put($key = 'foo', 'bar');

        $this->assertEquals("Written: {$key}", $this->getLastSentryBreadcrumb()->getMessage());

        Cache::forget($key);

        $this->assertEquals("Forgotten: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbForMissIsRecorded(): void
    {
        Cache::get($key = 'foo');

        $this->assertEquals("Missed: {$key}", $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertFalse($this->app->make('config')->boolean('sentry.breadcrumbs.cache'));

        Cache::get('foo');

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testCacheTelemetryHonorsEventsDisabledStoreConfiguration(): void
    {
        $this->resetApplicationWithConfig([
            'cache.stores.array.events' => false,
        ]);

        $transaction = $this->startTransaction();

        Cache::put('foo', 'bar');

        $this->assertFalse(config()->boolean('cache.stores.array.events'));
        $this->assertNull(Cache::store('array')->getEventDispatcher());
        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testCacheBreadcrumbReplacesSessionKeyWithPlaceholder(): void
    {
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();

        Cache::put($sessionId, 'session-data');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Written: {sessionKey}', $breadcrumb->getMessage());

        Cache::get($sessionId);

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Read: {sessionKey}', $breadcrumb->getMessage());
    }

    public function testCacheBreadcrumbDoesNotReplaceNonSessionKeys(): void
    {
        Cache::put('regular-key', 'value');

        $breadcrumb = $this->getLastSentryBreadcrumb();
        $this->assertEquals('Written: regular-key', $breadcrumb->getMessage());
    }

    public function testCacheGetSpanIsRecorded(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::get('foo');
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertFalse($span->getData()['cache.hit']);
    }

    public function testCacheGetSpanIsRecordedForBatchOperation(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::get(['foo', 'bar']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo, bar', $span->getDescription());
        $this->assertEquals(['foo', 'bar'], $span->getData()['cache.key']);
    }

    public function testCacheGetSpanIsRecordedForMultipleOperation(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::getMultiple(['foo', 'bar']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo, bar', $span->getDescription());
        $this->assertEquals(['foo', 'bar'], $span->getData()['cache.key']);
    }

    public function testCacheGetSpanIsRecordedWithCorrectHitData(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::put('foo', 'bar');
            Cache::get('foo');
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertTrue($span->getData()['cache.hit']);
    }

    public function testCachePutSpanIsRecorded(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::put('foo', 'bar', 99);
        });

        $this->assertEquals('cache.put', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
        $this->assertEquals(99, $span->getData()['cache.ttl']);
    }

    public function testCachePutSpanIsRecordedForManyOperation(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::putMany(['foo' => 'bar', 'baz' => 'qux'], 99);
        });

        $this->assertEquals('cache.put', $span->getOp());
        $this->assertEquals('foo, baz', $span->getDescription());
        $this->assertEquals(['foo', 'baz'], $span->getData()['cache.key']);
        $this->assertEquals(99, $span->getData()['cache.ttl']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testNestedBulkOperationsFinishOnlyAfterTheirLastResult(bool $writing): void
    {
        $this->resetApplicationWithConfig([
            'cache.stores.traced-failover' => [
                'driver' => 'failover',
                'stores' => ['array'],
                'events' => true,
            ],
        ]);
        Cache::store('array')->put('foo', 'value', 60);
        $transaction = $this->startTransaction();
        $openSpanCounts = [];
        $this->app->make('events')->listen(
            $writing ? [KeyWritten::class] : [CacheHit::class, CacheMissed::class],
            function () use ($transaction, &$openSpanCounts): void {
                $openSpanCounts[] = count(array_filter(
                    $transaction->getSpanRecorder()->getSpans(),
                    static fn (Span $span): bool => $span !== $transaction && $span->getEndTimestamp() === null,
                ));
            },
        );

        if ($writing) {
            $this->assertTrue(Cache::store('traced-failover')->putMany(['foo' => 'one', 'bar' => 'two'], 60));
        } else {
            $this->assertSame(
                ['foo' => 'value', 'bar' => null],
                Cache::store('traced-failover')->many(['foo', 'foo', 'bar']),
            );
        }

        $this->assertSame([2, 1, 1, 0], $openSpanCounts);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(3, $spans);
        $this->assertSame($transaction->getSpanId(), $spans[1]->getParentSpanId());
        $this->assertSame($spans[1]->getSpanId(), $spans[2]->getParentSpanId());
    }

    public function testCacheRemoveSpanIsRecorded(): void
    {
        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::forget('foo');
        });

        $this->assertEquals('cache.remove', $span->getOp());
        $this->assertEquals('foo', $span->getDescription());
        $this->assertEquals(['foo'], $span->getData()['cache.key']);
    }

    public function testCacheGetFailureFinishesItsSpanAndRethrows(): void
    {
        $exception = new RuntimeException('The cache read failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('get')->once()->with('foo')->andThrow($exception);

        $span = $this->executeFailureAndReturnMostRecentSpan(
            fn (): mixed => $this->repository($store)->get('foo'),
            $exception,
        );

        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
    }

    public function testCacheManyFailureFinishesItsSpanAndRethrows(): void
    {
        $exception = new RuntimeException('The cache batch read failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('many')->once()->with(['foo', 'bar'])->andThrow($exception);

        $span = $this->executeFailureAndReturnMostRecentSpan(
            fn (): array => $this->repository($store)->many(['foo', 'bar']),
            $exception,
        );

        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
    }

    public function testCachePutFailureFinishesItsSpanAndRethrows(): void
    {
        $exception = new RuntimeException('The cache write failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('put')->once()->with('foo', 'bar', 60)->andThrow($exception);

        $span = $this->executeFailureAndReturnMostRecentSpan(
            fn (): bool => $this->repository($store)->put('foo', 'bar', 60),
            $exception,
        );

        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
        $this->assertFalse($span->getData()['cache.success']);
    }

    public function testCachePutManyFailureFinishesItsSpanAndRethrows(): void
    {
        $exception = new RuntimeException('The cache batch write failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('putMany')->once()->with(['foo' => 'bar', 'baz' => 'qux'], 60)->andThrow($exception);

        $span = $this->executeFailureAndReturnMostRecentSpan(
            fn (): bool => $this->repository($store)->putMany(['foo' => 'bar', 'baz' => 'qux'], 60),
            $exception,
        );

        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
        $this->assertFalse($span->getData()['cache.success']);
    }

    public function testCacheForgetFailureFinishesItsSpanAndRethrows(): void
    {
        $exception = new RuntimeException('The cache forget failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('forget')->once()->with('foo')->andThrow($exception);

        $span = $this->executeFailureAndReturnMostRecentSpan(
            fn (): bool => $this->repository($store)->forget('foo'),
            $exception,
        );

        $this->assertSame(SpanStatus::internalError(), $span->getStatus());
    }

    public function testCacheForgetFalseResultFinishesItsSpanWithoutErrorStatus(): void
    {
        $store = m::mock(Store::class);
        $store->shouldReceive('forget')->once()->with('foo')->andReturnFalse();

        $span = $this->executeAndReturnMostRecentSpan(
            fn (): bool => $this->repository($store)->forget('foo'),
        );

        $this->assertNull($span->getStatus());
    }

    public function testCacheSpanReplacesSessionKeyWithPlaceholder(): void
    {
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();

        $span = $this->executeAndReturnMostRecentSpan(function () use ($sessionId): void {
            Cache::get($sessionId);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}', $span->getDescription());
        $this->assertEquals(['{sessionKey}'], $span->getData()['cache.key']);
    }

    public function testCacheSpanReplacesMultipleSessionKeysWithPlaceholder(): void
    {
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();

        $span = $this->executeAndReturnMostRecentSpan(function () use ($sessionId): void {
            Cache::get([$sessionId, 'regular-key', $sessionId . '_another']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}, regular-key, ' . $sessionId . '_another', $span->getDescription());
        $this->assertEquals(['{sessionKey}', 'regular-key', $sessionId . '_another'], $span->getData()['cache.key']);
    }

    // REMOVED: testCacheBackedSessionReadMissSpanIsRecordedAsSessionGet, testCacheBackedSessionReadHitSpanAndBreadcrumbAreRecordedAsSession,
    // testCacheBackedSessionWriteSpanIsRecordedAsSessionPut and testCacheBackedSessionDestroySpanIsRecordedAsSessionRemove.
    // Hypervel has no cache-backed session handlers, so no cache event is ever a session operation.

    public function testBatchCacheOperationContainingSessionKeyIsStillRecordedAsCache(): void
    {
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();

        $span = $this->executeAndReturnMostRecentSpan(static function () use ($sessionId): void {
            Cache::get([$sessionId, 'regular-key']);
        });

        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals('{sessionKey}, regular-key', $span->getDescription());
        $this->assertEquals(['{sessionKey}', 'regular-key'], $span->getData()['cache.key']);
        $this->assertFalse(isset($span->getData()['session.key']));
    }

    public function testCacheBreadcrumbReplacesSessionKeyFromRequestCookieWithoutResolvingSessionStore(): void
    {
        $sessionId = str_repeat('a', 40);

        $request = Request::create('/');
        $request->cookies->set($this->app->make('config')->string('session.cookie'), $sessionId);
        RequestContext::set($request);

        CacheIntegrationCacheAccessingSessionStore::$constructionCount = 0;

        $this->app->singleton('session.store', static function (): never {
            ++CacheIntegrationCacheAccessingSessionStore::$constructionCount;

            throw new RuntimeException('The session store should not be resolved when the request cookie is available.');
        });

        Cache::get($sessionId);

        $this->assertSame(0, CacheIntegrationCacheAccessingSessionStore::$constructionCount);
        $this->assertEquals('Missed: {sessionKey}', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheBreadcrumbUsesResolvedSessionStoreBeforeRequestCookie(): void
    {
        $cookieSessionId = str_repeat('a', 40);

        $request = Request::create('/');
        $request->cookies->set($this->app->make('config')->string('session.cookie'), $cookieSessionId);
        RequestContext::set($request);

        $this->startSession();

        $sessionStoreSessionId = $this->app->make('session.store')->getId();

        $this->assertNotSame($cookieSessionId, $sessionStoreSessionId);

        Cache::get($sessionStoreSessionId);

        $this->assertEquals('Missed: {sessionKey}', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheSessionKeyDetectionDoesNotReenterSessionStoreConstruction(): void
    {
        CacheIntegrationCacheAccessingSessionStore::$cacheKey = 'browser-detect-like-cache-key';
        CacheIntegrationCacheAccessingSessionStore::$constructionCount = 0;

        $this->app->singleton('session.store', static function (): CacheIntegrationCacheAccessingSessionStore {
            return new CacheIntegrationCacheAccessingSessionStore;
        });

        Cache::get('outer-cache-key');

        $this->assertSame(1, CacheIntegrationCacheAccessingSessionStore::$constructionCount);
        $this->assertEquals('Missed: outer-cache-key', $this->getLastSentryBreadcrumb()->getMessage());
    }

    public function testCacheOperationDoesNotStartSessionPrematurely(): void
    {
        // Don't start a session to ensure it's not started

        $span = $this->executeAndReturnMostRecentSpan(function (): void {
            Cache::get('some-key');
        });

        // Check that session was not started
        $this->assertFalse($this->app->make('session')->isStarted());

        // And the key should not be replaced
        $this->assertEquals('some-key', $span->getDescription());
    }

    public function testRedisParametersAreRecordedWhenPIIShouldBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => true,
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertSame('SET foo', $span->getDescription());
        $this->assertSame(['foo', 'bar'], $span->getData()['db.redis.parameters']);
    }

    public function testRedisParametersAreNotRecordedWhenPIIShouldNotBeSent(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => false,
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertArrayNotHasKey('db.redis.parameters', $span->getData());
    }

    public function testRedisParametersAreRecordedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => false,
            'sentry.data_collection' => [],
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertSame(['foo', 'bar'], $span->getData()['db.redis.parameters']);
    }

    public function testRedisParametersAreNotRecordedWhenDatabaseQueryDataIsDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => true,
            'sentry.send_default_pii' => true,
            'sentry.data_collection' => [
                'database_query_data' => false,
            ],
        ]);

        $span = $this->executeRedisCommandAndReturnSpan();

        $this->assertArrayNotHasKey('db.redis.parameters', $span->getData());
    }

    public function testRedisCommandsAreRecordedWithoutCacheTelemetry(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.cache' => false,
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertTrue($this->app->make(CacheIntegration::class)->isApplicable());
        $this->assertSame('SET foo', $this->executeRedisCommandAndReturnSpan()->getDescription());
    }

    public function testRedisEventsAreNotEnabledWithoutRedisCommandTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => false,
        ]);

        $this->assertTrue($this->app->make(CacheIntegration::class)->isApplicable());
        $this->assertFalse($this->app->resolved(RedisManager::class));
        $this->assertFalse($this->app->make(RedisConfig::class)->connectionConfig('default')['events']);
    }

    public function testFeatureIsNotApplicableWhenRedisAndCacheTelemetryAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.redis_commands' => false,
            'sentry.tracing.cache' => false,
            'sentry.breadcrumbs.cache' => false,
        ]);

        $this->assertFalse($this->app->make(CacheIntegration::class)->isApplicable());
    }

    public function testRedisCommandTracingIsDisabledWhenOmitted(): void
    {
        $tracing = config()->array('sentry.tracing');
        unset($tracing['redis_commands']);
        $tracing['cache'] = false;
        config()->set('sentry.tracing', $tracing);
        config()->set('sentry.breadcrumbs.cache', false);

        $this->assertFalse((new CacheIntegration($this->app))->isApplicable());
    }

    public function testFeatureEnablesRedisEventsForFuturePools(): void
    {
        $config = $this->app->make('config');
        $config->set('database.redis.observed', $config->array('database.redis.default'));

        $this->assertTrue(
            $this->app->make(RedisConfig::class)
                ->connectionConfig('observed')['events'],
        );
    }

    public function testRedisCommandCreatesSpanWhenParentSpanExists(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals('GET test-key', $redisSpan->getDescription());

        $spanData = $redisSpan->getData();
        $this->assertSame(Coroutine::id(), $spanData['coroutine.id']);
        $this->assertEquals('redis', $spanData['db.system']);
        $this->assertEquals('default', $spanData['db.redis.connection']);
        $this->assertArrayNotHasKey('db.redis.parameters', $spanData);
    }

    public function testRedisCommandWithSessionKeyReplacesWithPlaceholder(): void
    {
        $this->registerObservedRedisPool();
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', [$sessionId], 0.005, $this->createRedisConnection('default')));

        $this->assertEquals('GET {sessionKey}', $transaction->getSpanRecorder()->getSpans()[1]->getDescription());
    }

    public function testRedisParametersRequirePiiConsentAndRedactSessionKey(): void
    {
        $this->resetApplicationWithConfig(['sentry.send_default_pii' => true]);
        $this->registerObservedRedisPool();
        $this->startSession();
        $sessionId = $this->app->make('session')->getId();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('SET', [$sessionId, 'value'], 0.005, $this->createRedisConnection('default')));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];

        $this->assertSame(['{sessionKey}', 'value'], $redisSpan->getData()['db.redis.parameters']);
    }

    public function testRedisKeyZeroIsPreservedInDescription(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['0'], 0.005, $this->createRedisConnection('default')));

        $this->assertSame('GET 0', $transaction->getSpanRecorder()->getSpans()[1]->getDescription());
    }

    public function testRedisSessionKeyFallbackIsReentrySafe(): void
    {
        $this->registerObservedRedisPool();
        $connection = $this->createRedisConnection('default');
        $session = m::mock(Session::class);
        $session->shouldReceive('getId')->once()->andReturn('outer-key');
        $this->app->bind('session.store', function () use ($connection, $session): Session {
            $this->dispatchHypervelEvent(new CommandExecuted('GET', ['inner-key'], 0.005, $connection));

            return $session;
        });
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['outer-key'], 0.005, $connection));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(3, $spans);
        $this->assertSame('GET inner-key', $spans[1]->getDescription());
        $this->assertSame('GET {sessionKey}', $spans[2]->getDescription());
    }

    public function testRedisSessionResolutionThrowableDoesNotBreakCommandTracing(): void
    {
        $this->registerObservedRedisPool();
        $this->app->bind('session.store', static fn (): never => throw new Error('Session resolution failed.'));
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $this->assertSame('GET test-key', $transaction->getSpanRecorder()->getSpans()[1]->getDescription());
    }

    public function testRedisCommandWithoutParentSpanDoesNotCreateSpan(): void
    {
        $this->registerObservedRedisPool();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $this->assertEmpty($this->getCapturedSentryEvents());
    }

    public function testRedisCommandWithUnsampledParentSpanDoesNotCreateSpan(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();
        $transaction->setSampled(false);

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $this->assertCount(1, $transaction->getSpanRecorder()->getSpans());
    }

    public function testRedisCommandWithMultilineKeyUsesEmptyDescription(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('SET', ["multi\nline\nkey", 'value'], 0.005, $this->createRedisConnection('default')));

        $this->assertEquals('SET', $transaction->getSpanRecorder()->getSpans()[1]->getDescription());
    }

    public function testRedisCommandWithNonStringKeyUsesEmptyKey(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('DEL', [123], 0.005, $this->createRedisConnection('default')));

        $this->assertEquals('DEL', $transaction->getSpanRecorder()->getSpans()[1]->getDescription());
    }

    public function testRedisCommandIncludesPoolInformation(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $spanData = $transaction->getSpanRecorder()->getSpans()[1]->getData();

        $this->assertEquals('default', $spanData['db.redis.pool.name']);
        $this->assertEquals(10, $spanData['db.redis.pool.max']);
        $this->assertEquals(60.0, $spanData['db.redis.pool.max_idle_time']);
        $this->assertEquals(5, $spanData['db.redis.pool.idle']);
        $this->assertEquals(7, $spanData['db.redis.pool.managed']);
        $this->assertEquals(2, $spanData['db.redis.pool.borrowed']);
        $this->assertEquals(1, $spanData['db.redis.pool.waiting']);
        $this->assertArrayNotHasKey('db.redis.pool.using', $spanData);
    }

    public function testRedisCommandWithDifferentConfiguration(): void
    {
        $this->registerObservedRedisPool('cache', 1);
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('SET', ['cache-key', 'value'], 0.010, $this->createRedisConnection('cache')));

        $redisSpan = $transaction->getSpanRecorder()->getSpans()[1];
        $spanData = $redisSpan->getData();

        $this->assertEquals('cache', $spanData['db.redis.connection']);
        $this->assertEquals(1, $spanData['db.redis.database_index']);
        $this->assertEqualsWithDelta(0.00001, $redisSpan->getEndTimestamp() - $redisSpan->getStartTimestamp(), 0.000001);
    }

    public function testRedisTracingWorksAfterReplacingStaleGlobalHub(): void
    {
        $staleHub = new Hub;
        SentrySdk::setCurrentHub($staleHub);

        $this->reloadApplication();
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $this->assertNotSame($staleHub, SentrySdk::getCurrentHub());
        $this->assertSame($this->app->make(HubInterface::class), SentrySdk::getCurrentHub());
        $this->assertCount(2, $transaction->getSpanRecorder()->getSpans());
    }

    public function testFailedRedisCommandCreatesErrorSpanWithTime(): void
    {
        $this->registerObservedRedisPool('cache', 2);
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandFailed('GET', ['test-key'], new Exception('Connection refused'), $this->createRedisConnection('cache'), 0.005));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals('GET test-key', $redisSpan->getDescription());
        $this->assertEquals(SpanStatus::internalError(), $redisSpan->getStatus());
        $this->assertEqualsWithDelta(0.000005, $redisSpan->getEndTimestamp() - $redisSpan->getStartTimestamp(), 0.000001);

        $spanData = $redisSpan->getData();
        $this->assertEquals('redis', $spanData['db.system']);
        $this->assertEquals('Connection refused', $spanData['db.redis.error']);
        $this->assertEquals('cache', $spanData['db.redis.connection']);
        $this->assertEquals(2, $spanData['db.redis.database_index']);
        $this->assertEquals('cache', $spanData['db.redis.pool.name']);
        $this->assertEquals(10, $spanData['db.redis.pool.max']);
    }

    public function testFailedRedisCommandCreatesErrorSpanWithoutTime(): void
    {
        $this->registerObservedRedisPool();
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandFailed('GET', ['test-key'], new Exception('Connection refused'), $this->createRedisConnection('default')));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);

        $redisSpan = $spans[1];
        $this->assertEquals('db.redis', $redisSpan->getOp());
        $this->assertEquals(SpanStatus::internalError(), $redisSpan->getStatus());
        $this->assertSame($redisSpan->getStartTimestamp(), $redisSpan->getEndTimestamp());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTracingWithoutARegisteredPoolDoesNotCreateOne(bool $purgePool): void
    {
        config()->set('database.redis.default.pool.heartbeat_interval', 1.0);
        $manager = $this->app->make(PoolManager::class);
        $timerCount = Timer::stats()['num'];

        try {
            if ($purgePool) {
                $pool = $manager->pool('default');
                $manager->purge('default');
                $this->assertTrue($pool->isClosed());
            }

            $this->assertSame([], $manager->getPools());
            $transaction = $this->startTransaction();

            $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

            $spans = $transaction->getSpanRecorder()->getSpans();
            $this->assertCount(2, $spans);
            $this->assertSame('GET test-key', $spans[1]->getDescription());
            $this->assertArrayNotHasKey('db.redis.pool.name', $spans[1]->getData());
            $this->assertSame([], $manager->getPools());
            $this->assertSame($timerCount, Timer::stats()['num']);
        } finally {
            $manager->purgeAll();
        }
    }

    public function testRedisSpanRetainsNullIdleTimeout(): void
    {
        $this->registerObservedRedisPool(maxIdleTime: null);
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new CommandExecuted('GET', ['test-key'], 0.005, $this->createRedisConnection('default')));

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);
        $data = $spans[1]->getData();
        $this->assertArrayHasKey('db.redis.pool.max_idle_time', $data);
        $this->assertNull($data['db.redis.pool.max_idle_time']);
    }

    /**
     * Dispatch a Redis command within a transaction and return its span.
     */
    private function executeRedisCommandAndReturnSpan(): Span
    {
        return $this->executeAndReturnMostRecentSpan(function (): void {
            $this->dispatchHypervelEvent(new CommandExecuted('set', ['foo', 'bar'], 1.0, $this->createRedisConnection('default')));
        });
    }

    /**
     * Run the callback within a transaction and return the most recent span.
     */
    private function executeAndReturnMostRecentSpan(callable $callable): Span
    {
        $transaction = $this->startTransaction();

        $callable();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertTrue(count($spans) >= 2);

        return array_pop($spans);
    }

    /**
     * Run the failing callback within a transaction and return the most recent span.
     */
    private function executeFailureAndReturnMostRecentSpan(callable $callable, RuntimeException $exception): Span
    {
        $transaction = $this->startTransaction();

        try {
            $callable();
            $this->fail('Expected the cache exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $this->assertNotNull($spans[1]->getEndTimestamp());

        return array_pop($spans);
    }

    /**
     * Create a cache repository for the given store that dispatches events.
     */
    private function repository(Store $store): Repository
    {
        $repository = new Repository($store, ['store' => 'test']);
        $repository->setEventDispatcher($this->app->make('events'));

        return $repository;
    }

    /**
     * Register an observed Redis pool.
     */
    private function registerObservedRedisPool(string $connectionName = 'default', int $database = 0, ?float $maxIdleTime = 60.0): void
    {
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('getOptions')->andReturn(PoolOptions::fromArray(['max_idle_time' => $maxIdleTime]));
        $pool->shouldReceive('getManagedCount')->andReturn(7);
        $pool->shouldReceive('getBorrowedCount')->andReturn(2);
        $pool->shouldReceive('getIdleCount')->andReturn(5);
        $pool->shouldReceive('getWaitingCount')->andReturn(1);

        $poolManager = m::mock(PoolManager::class);
        $poolManager->shouldReceive('getPools')->andReturn([$connectionName => $pool]);
        $poolManager->shouldNotReceive('pool');

        $this->app->instance(PoolManager::class, $poolManager);

        $this->app->make('config')->set("database.redis.{$connectionName}.database", $database);
    }

    /**
     * Create a Redis connection with the given name.
     */
    private function createRedisConnection(string $name): RedisConnection
    {
        $connection = m::mock(PhpRedisConnection::class);
        $connection->shouldReceive('getName')->andReturn($name);

        return $connection;
    }
}

class CacheIntegrationCacheAccessingSessionStore extends SessionStore
{
    public static int $constructionCount = 0;

    public static string $cacheKey = 'browser-detect-like-cache-key';

    /**
     * Create a session store that reads the cache while it is constructed.
     */
    public function __construct()
    {
        ++self::$constructionCount;

        if (self::$constructionCount > 3) {
            throw new RuntimeException('Session store construction re-entered too many times.');
        }

        Cache::remember(self::$cacheKey, 60, static function (): string {
            return 'bot-detection-result';
        });

        parent::__construct('hypervel_session', new NullSessionHandler);
    }
}
