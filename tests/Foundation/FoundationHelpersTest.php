<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\FoundationHelpersTest;

use DateTimeZone;
use Hypervel\Broadcasting\FakePendingBroadcast;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Cache\CacheManager;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Log\LogManager;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

enum StringEnum: string
{
    case Utc = 'UTC';
    case NewYork = 'America/New_York';
}

enum IntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum UnitEnum
{
    case UTC;
    case EST;
}

class FoundationHelpersTest extends TestCase
{
    public function testAppPathUsesTheConfiguredApplicationDirectory(): void
    {
        $this->assertSame($this->app->basePath('app'), app_path());
        $this->assertSame($this->app->basePath('app/Models'), app_path('Models'));

        $path = $this->app->basePath('custom-app');
        $this->app->useAppPath($path);

        $this->assertSame($path, app_path());
        $this->assertSame($path . '/Models', app_path('Models'));
    }

    public function testAppPathUsesBasePathBeforeApplicationBootstrap(): void
    {
        Container::setInstance(new Container);

        try {
            $this->assertSame(BASE_PATH . '/app', app_path());
            $this->assertSame(BASE_PATH . '/app/Models', app_path('Models'));
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function testNowReturnsCarbonImmutableByDefault(): void
    {
        $result = now();

        $this->assertSame(CarbonImmutable::class, $result::class);
    }

    public function testNowHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $this->assertSame(Carbon::class, now()::class);
    }

    public function testNowWithStringTimezone(): void
    {
        $result = now('America/New_York');

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = now($tz);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithStringBackedEnum(): void
    {
        $result = now(StringEnum::NewYork);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithUnitEnum(): void
    {
        $result = now(UnitEnum::UTC);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testNowWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = now(IntEnum::One);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testNowWithNull(): void
    {
        $result = now(null);

        $this->assertSame(CarbonImmutable::class, $result::class);
    }

    public function testTodayReturnsCarbonImmutableByDefault(): void
    {
        $result = today();

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $this->assertSame(Carbon::class, today()::class);
    }

    public function testTodayWithStringTimezone(): void
    {
        $result = today('America/New_York');

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = today($tz);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithStringBackedEnum(): void
    {
        $result = today(StringEnum::NewYork);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithUnitEnum(): void
    {
        $result = today(UnitEnum::UTC);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testTodayWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = today(IntEnum::One);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testTodayWithNull(): void
    {
        $result = today(null);

        $this->assertSame(CarbonImmutable::class, $result::class);
    }

    public function testCache(): void
    {
        $cache = m::mock(CacheManager::class);
        $this->app->instance('cache', $cache);

        // cache() returns the CacheManager
        $this->assertInstanceOf(CacheManager::class, cache());

        // cache(['foo' => 'bar'], 1) puts
        $cache->shouldReceive('put')->once()->with('foo', 'bar', 1);
        cache(['foo' => 'bar'], 1);

        // cache('foo') gets
        $cache->shouldReceive('get')->once()->with('foo', null)->andReturn('bar');
        $this->assertSame('bar', cache('foo'));

        // cache('foo', null) gets with null default
        $cache->shouldReceive('get')->once()->with('foo', null)->andReturn('bar');
        $this->assertSame('bar', cache('foo', null));

        // cache('baz', 'default') gets with default
        $cache->shouldReceive('get')->once()->with('baz', 'default')->andReturn('default');
        $this->assertSame('default', cache('baz', 'default'));
    }

    public function testLogsResolvesAChannelNamedZero(): void
    {
        $manager = m::mock(LogManager::class);
        $logger = m::mock(LoggerInterface::class);
        $manager->shouldReceive('driver')->once()->with('0')->andReturn($logger);
        $this->app->instance('log', $manager);

        $this->assertSame($logger, logs('0'));
        $this->assertSame($manager, logs());
    }

    public function testEvents()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $this->app->instance('events', $dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with('test.event', ['payload'], false)->andReturn('foo');
        $this->assertSame('foo', event('test.event', ['payload'], false));
    }

    public function testEventHelperReturnsArrayForNormalDispatch()
    {
        Event::listen('test.event', function () {
            return 'response';
        });

        $result = event('test.event');

        $this->assertIsArray($result);
        $this->assertSame(['response'], $result);
    }

    public function testEventHelperReturnsNonArrayForHaltedDispatch()
    {
        Event::listen('test.halted', function () {
            return 42;
        });

        $result = event('test.halted', [], true);

        $this->assertSame(42, $result);
    }

    public function testEventHelperReturnsNullWhenNoListenersAndHalted()
    {
        $result = event('test.no-listeners', [], true);

        $this->assertNull($result);
    }

    public function testEventHelperReturnsEmptyArrayWhenNoListeners()
    {
        $result = event('test.no-listeners');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testAbortReceivesCodeAsSymfonyResponseInstance()
    {
        try {
            abort($code = new SymfonyResponse);

            $this->fail(
                sprintf('abort function must throw %s when receiving code as Symfony Response instance.', HttpResponseException::class)
            );
        } catch (HttpResponseException $exception) {
            $this->assertSame($code, $exception->getResponse());
        }
    }

    public function testAbortReceivesCodeAsResponsableImplementation()
    {
        $request = \Hypervel\Http\Request::create('/');
        RequestContext::set($request);

        try {
            abort($code = new class implements Responsable {
                public ?\Hypervel\Http\Request $request = null;

                public function toResponse(\Hypervel\Http\Request $request): SymfonyResponse
                {
                    $this->request = $request;

                    return new SymfonyResponse;
                }
            });

            $this->fail(
                sprintf('abort function must throw %s when receiving code as Responsable implementation.', HttpResponseException::class)
            );
        } catch (HttpResponseException) {
            $this->assertSame($request, $code->request);
        }
    }

    public function testAbortReceivesCodeAsInteger()
    {
        try {
            abort(400, 'Bad request', ['X-FOO' => 'BAR']);

            $this->fail('abort function must throw HttpException when receiving code as integer.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(400, $exception->getStatusCode());
            $this->assertSame('Bad request', $exception->getMessage());
            $this->assertSame('BAR', $exception->getHeaders()['X-FOO']);
        }
    }

    public function testBroadcastIfReturnsFakeOnFalse()
    {
        $this->assertInstanceOf(FakePendingBroadcast::class, broadcast_if(false, 'foo'));
    }

    public function testBroadcastIfReturnsRealBroadcastOnTrue()
    {
        $result = broadcast_if(true, new stdClass);

        $this->assertInstanceOf(PendingBroadcast::class, $result);
        $this->assertNotInstanceOf(FakePendingBroadcast::class, $result);
    }

    public function testBroadcastIfEvaluatesEventLazily()
    {
        $evaluated = false;

        broadcast_if(false, function () use (&$evaluated) {
            $evaluated = true;
            return new stdClass;
        });

        $this->assertFalse($evaluated, 'Event closure should not be evaluated when condition is false');
    }

    public function testBroadcastUnlessReturnsFakeOnTrue()
    {
        $this->assertInstanceOf(FakePendingBroadcast::class, broadcast_unless(true, 'foo'));
    }

    public function testBroadcastUnlessReturnsRealBroadcastOnFalse()
    {
        $result = broadcast_unless(false, new stdClass);

        $this->assertInstanceOf(PendingBroadcast::class, $result);
        $this->assertNotInstanceOf(FakePendingBroadcast::class, $result);
    }

    public function testFakePendingBroadcastMethodsAreNoOps()
    {
        $fake = new FakePendingBroadcast;

        $this->assertSame($fake, $fake->via('pusher'));
        $this->assertSame($fake, $fake->toOthers());
    }

    public function testDeferReturnsDeferredCallbackWhenCallbackProvided()
    {
        $deferred = defer(fn () => null);

        $this->assertInstanceOf(DeferredCallback::class, $deferred);
    }

    public function testDeferReturnsCollectionWhenCallbackIsNull()
    {
        $this->assertInstanceOf(DeferredCallbackCollection::class, defer());
    }

    public function testDeferWithoutNameQueuesCallbackUntilCollectionInvoked()
    {
        $executed = false;
        defer(function () use (&$executed) {
            $executed = true;
        });

        $this->assertFalse($executed);

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertTrue($executed);
    }

    public function testDeferWithNameDeduplicatesCallbacksWhenCollectionInvoked()
    {
        $results = [];

        defer(function () use (&$results) {
            $results[] = 'first';
        }, 'sync-metrics');

        defer(function () use (&$results) {
            $results[] = 'second';
        }, 'sync-metrics');

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertSame(['second'], $results);
    }

    public function testDeferWithDifferentNamesRunsBothWhenCollectionInvoked()
    {
        $results = [];

        defer(function () use (&$results) {
            $results[] = 'foo';
        }, 'foo');

        defer(function () use (&$results) {
            $results[] = 'bar';
        }, 'bar');

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertSame(['foo', 'bar'], $results);
    }

    public function testDeferWithNamedAndUnnamedBothExecuteWhenCollectionInvoked()
    {
        $results = [];

        defer(function () use (&$results) {
            $results[] = 'unnamed';
        });

        defer(function () use (&$results) {
            $results[] = 'named';
        }, 'my-name');

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertSame(['unnamed', 'named'], $results);
    }

    public function testDeferStoresAlwaysFlag()
    {
        $deferred = defer(fn () => null, always: true);

        $this->assertTrue($deferred->always);
    }
}
