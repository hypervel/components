<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Scout\Searchable;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery as m;
use RuntimeException;

class SearchableDispatchTest extends ScoutTestCase
{
    protected bool $migrateRefresh = false;

    public function testDispatchRunsImmediatelyOutsideARequest(): void
    {
        $executed = false;

        SearchableDispatchFixture::dispatch(static function () use (&$executed): void {
            $executed = true;
        });

        $this->assertTrue($executed);
    }

    public function testRequestJobsRunAfterTheRequestCoroutineInFifoOrder(): void
    {
        $events = [];

        $coroutineId = Coroutine::create(static function () use (&$events): void {
            RequestContext::set(Request::create('/'));

            SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'save';
            });
            AlternateSearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'delete';
            });

            $events[] = 'response';
        });

        Coroutine::join([$coroutineId], 1);
        $this->assertSame(['response', 'save', 'delete'], $events);
    }

    public function testDeferredOwnerDrainsJobsEnqueuedWhileItIsRunning(): void
    {
        $events = [];

        $coroutineId = Coroutine::create(static function () use (&$events): void {
            RequestContext::set(Request::create('/'));

            SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'first';
                SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                    $events[] = 'reentrant';
                });
            });
            SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'second';
            });
        });

        Coroutine::join([$coroutineId], 1);
        $this->assertSame(['first', 'second', 'reentrant'], $events);
    }

    public function testAReportedFailureDoesNotStopLaterJobs(): void
    {
        $exception = new RuntimeException('indexing failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($exception);
        $this->app->instance(ExceptionHandler::class, $handler);
        $events = [];

        $coroutineId = Coroutine::create(static function () use ($exception, &$events): void {
            RequestContext::set(Request::create('/'));

            SearchableDispatchFixture::dispatch(static function () use ($exception): void {
                throw $exception;
            });
            SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'continued';
            });
        });

        Coroutine::join([$coroutineId], 1);
        $this->assertSame(['continued'], $events);
    }

    public function testAUserDeferCanCreateANewOwnerAfterTheFirstOwnerDrains(): void
    {
        $events = [];

        $coroutineId = Coroutine::create(static function () use (&$events): void {
            RequestContext::set(Request::create('/'));

            Coroutine::defer(static function () use (&$events): void {
                SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                    $events[] = 'late';
                });
            });

            SearchableDispatchFixture::dispatch(static function () use (&$events): void {
                $events[] = 'initial';
            });
        });

        Coroutine::join([$coroutineId], 1);
        $this->assertSame(['initial', 'late'], $events);
    }
}

class SearchableDispatchFixture
{
    use Searchable;

    public static function dispatch(callable $job): void
    {
        self::dispatchSearchableJob($job);
    }
}

class AlternateSearchableDispatchFixture
{
    use Searchable;

    public static function dispatch(callable $job): void
    {
        self::dispatchSearchableJob($job);
    }
}
