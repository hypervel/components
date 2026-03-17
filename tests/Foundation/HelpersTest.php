<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Carbon\Carbon;
use DateTimeZone;
use Hypervel\Broadcasting\FakePendingBroadcast;
use Hypervel\Broadcasting\PendingBroadcast;
use Hypervel\Cache\CacheManager;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

enum HelpersTestStringEnum: string
{
    case UTC = 'UTC';
    case NewYork = 'America/New_York';
}

enum HelpersTestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum HelpersTestUnitEnum
{
    case UTC;
    case EST;
}

/**
 * @internal
 * @coversNothing
 */
class HelpersTest extends TestCase
{
    public function testNowReturnsCarbon(): void
    {
        $result = now();

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testNowWithStringTimezone(): void
    {
        $result = now('America/New_York');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = now($tz);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithStringBackedEnum(): void
    {
        $result = now(HelpersTestStringEnum::NewYork);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithUnitEnum(): void
    {
        $result = now(HelpersTestUnitEnum::UTC);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testNowWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = now(HelpersTestIntEnum::One);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testNowWithNull(): void
    {
        $result = now(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testTodayReturnsCarbon(): void
    {
        $result = today();

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayWithStringTimezone(): void
    {
        $result = today('America/New_York');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = today($tz);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithStringBackedEnum(): void
    {
        $result = today(HelpersTestStringEnum::NewYork);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithUnitEnum(): void
    {
        $result = today(HelpersTestUnitEnum::UTC);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testTodayWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = today(HelpersTestIntEnum::One);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testTodayWithNull(): void
    {
        $result = today(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testCache()
    {
        $cache = m::mock(CacheManager::class);
        $this->app['cache'] = $cache;

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
            abort($code = new SymfonyResponse());

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
        $this->app->instance('request', $request);

        try {
            abort($code = new class implements Responsable {
                public ?\Hypervel\Http\Request $request = null;

                public function toResponse(\Hypervel\Http\Request $request): SymfonyResponse
                {
                    $this->request = $request;

                    return new SymfonyResponse();
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
        $result = broadcast_if(true, new stdClass());

        $this->assertInstanceOf(PendingBroadcast::class, $result);
        $this->assertNotInstanceOf(FakePendingBroadcast::class, $result);
    }

    public function testBroadcastIfEvaluatesEventLazily()
    {
        $evaluated = false;

        broadcast_if(false, function () use (&$evaluated) {
            $evaluated = true;
            return new stdClass();
        });

        $this->assertFalse($evaluated, 'Event closure should not be evaluated when condition is false');
    }

    public function testBroadcastUnlessReturnsFakeOnTrue()
    {
        $this->assertInstanceOf(FakePendingBroadcast::class, broadcast_unless(true, 'foo'));
    }

    public function testBroadcastUnlessReturnsRealBroadcastOnFalse()
    {
        $result = broadcast_unless(false, new stdClass());

        $this->assertInstanceOf(PendingBroadcast::class, $result);
        $this->assertNotInstanceOf(FakePendingBroadcast::class, $result);
    }

    public function testFakePendingBroadcastMethodsAreNoOps()
    {
        $fake = new FakePendingBroadcast();

        $this->assertSame($fake, $fake->via('pusher'));
        $this->assertSame($fake, $fake->toOthers());
    }

    public function testDeferWithoutNameExecutesCallback()
    {
        $executed = false;

        \Swoole\Coroutine::create(function () use (&$executed) {
            defer(function () use (&$executed) {
                $executed = true;
            });
        });

        $this->assertTrue($executed);
    }

    public function testDeferWithNameDeduplicatesCallbacks()
    {
        $results = [];

        \Swoole\Coroutine::create(function () use (&$results) {
            defer(function () use (&$results) {
                $results[] = 'first';
            }, 'sync-metrics');

            defer(function () use (&$results) {
                $results[] = 'second';
            }, 'sync-metrics');
        });

        $this->assertSame(['second'], $results);
    }

    public function testDeferWithDifferentNamesRunsBoth()
    {
        $results = [];

        \Swoole\Coroutine::create(function () use (&$results) {
            defer(function () use (&$results) {
                $results[] = 'foo';
            }, 'foo');

            defer(function () use (&$results) {
                $results[] = 'bar';
            }, 'bar');
        });

        $this->assertCount(2, $results);
        $this->assertContains('foo', $results);
        $this->assertContains('bar', $results);
    }

    public function testDeferWithNamedAndUnnamedBothExecute()
    {
        $results = [];

        \Swoole\Coroutine::create(function () use (&$results) {
            defer(function () use (&$results) {
                $results[] = 'unnamed';
            });

            defer(function () use (&$results) {
                $results[] = 'named';
            }, 'my-name');
        });

        $this->assertCount(2, $results);
        $this->assertContains('unnamed', $results);
        $this->assertContains('named', $results);
    }
}
