<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class RequestDurationThresholdTest extends TestCase
{
    public function testItCanHandleExceedingRequestDuration(): void
    {
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan(CarbonInterval::second(), function () use (&$called): void {
            $called = true;
        });

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond()->addMillisecond());
        $kernel->terminate($request, $response);

        $this->assertTrue($called);
    }

    public function testItDoesntCallWhenExactlyThresholdDuration(): void
    {
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan(CarbonInterval::second(), function () use (&$called): void {
            $called = true;
        });

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond());
        $kernel->terminate($request, $response);

        $this->assertFalse($called);
    }

    public function testItProvidesRequestToHandler(): void
    {
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $url = null;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan(CarbonInterval::second(), function (CarbonImmutable $startedAt, Request $request) use (&$url): void {
            $url = $request->url();
        });

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSeconds(2));
        $kernel->terminate($request, $response);

        $this->assertSame('http://localhost/test-route', $url);
    }

    public function testUsesTheConfiguredDateTimezone(): void
    {
        config(['app.timezone' => 'UTC']);
        Route::get('test-route', fn (): string => 'ok');
        $kernel = $this->app->make(Kernel::class);
        $startedAt = null;
        $kernel->whenRequestLifecycleIsLongerThan(CarbonInterval::second(), function (CarbonImmutable $started) use (&$startedAt): void {
            $startedAt = $started;
        });

        config(['app.timezone' => 'Australia/Melbourne']);
        CarbonImmutable::setTestNow($now = CarbonImmutable::today());
        $kernel->handle($request = Request::create('http://localhost/test-route'));
        CarbonImmutable::setTestNow($now->addMinute());
        $kernel->terminate($request, new Response);

        $this->assertSame('Australia/Melbourne', $startedAt->timezone->getName());
    }

    public function testItCanExceedThresholdWhenSpecifyingDurationAsMilliseconds(): void
    {
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan(1000, function () use (&$called): void {
            $called = true;
        });

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond()->addMillisecond());
        $kernel->terminate($request, $response);

        $this->assertTrue($called);
    }

    public function testItCanStayUnderThresholdWhenSpecifyingDurationAsMilliseconds(): void
    {
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan(1000, function () use (&$called): void {
            $called = true;
        });

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond());
        $kernel->terminate($request, $response);

        $this->assertFalse($called);
    }

    public function testItCanExceedThresholdWhenSpecifyingDurationAsDateTime(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan($now->addSecond(), function () use (&$called): void {
            $called = true;
        });

        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond()->addMillisecond());
        $kernel->terminate($request, $response);

        $this->assertTrue($called);
    }

    public function testItCanStayUnderThresholdWhenSpecifyingDurationAsDateTime(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;
        $called = false;
        $kernel = $this->app->make(Kernel::class);
        $kernel->whenRequestLifecycleIsLongerThan($now->addSecond(), function () use (&$called): void {
            $called = true;
        });

        $kernel->handle($request);

        CarbonImmutable::setTestNow($now->addSecond());
        $kernel->terminate($request, $response);

        $this->assertFalse($called);
    }

    public function testItClearsStartTimeAfterHandlingRequest(): void
    {
        $kernel = $this->app->make(Kernel::class);
        Route::get('test-route', fn (): string => 'ok');
        $request = Request::create('http://localhost/test-route');
        $response = new Response;

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());
        $kernel->handle($request);
        $this->assertTrue($now->eq($kernel->requestStartedAt()));

        $kernel->terminate($request, $response);
        $this->assertNull($kernel->requestStartedAt());
    }

    public function testItHandlesCallingTerminateWithoutHandle(): void
    {
        $this->app->make(Kernel::class)->terminate(Request::create('http://localhost/test-route'), new Response);

        // this is a placeholder just to show that the above did not throw an exception.
        $this->assertTrue(true);
    }
}
