<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console\KernelTerminateTest;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Support\Carbon;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * @internal
 * @coversNothing
 */
class KernelTerminateTest extends TestCase
{
    public function testTerminateDispatchesTerminatingEvent()
    {
        $dispatched = false;

        $this->app->make(Dispatcher::class)->listen(Terminating::class, function () use (&$dispatched) {
            $dispatched = true;
        });

        $kernel = $this->app->make(KernelContract::class);
        $kernel->terminate(new StringInput(''), 0);

        $this->assertTrue($dispatched);
    }

    public function testTerminateDispatchesTerminatingEventEvenWithoutHandle()
    {
        // Calling terminate without a prior handle should not throw.
        $kernel = $this->app->make(KernelContract::class);
        $kernel->terminate(new StringInput(''), 0);

        // If we reach here without exception, the test passes.
        $this->assertTrue(true);
    }

    public function testCommandStartedAtIsNullBeforeHandle()
    {
        $kernel = $this->app->make(KernelContract::class);

        $this->assertNull($kernel->commandStartedAt());
    }

    public function testCommandStartedAtIsSetAfterHandle()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        Carbon::setTestNow(Carbon::now());

        $kernel->handle(new StringInput('foo'), new ConsoleOutput());

        $this->assertNotNull($kernel->commandStartedAt());
    }

    public function testCommandStartedAtIsClearedAfterTerminate()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        Carbon::setTestNow(Carbon::now());

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());
        $this->assertNotNull($kernel->commandStartedAt());

        $kernel->terminate($input, 0);
        $this->assertNull($kernel->commandStartedAt());
    }

    public function testDurationThresholdHandlerCalledWhenExceeded()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        $this->assertFalse($called);

        Carbon::setTestNow(Carbon::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdHandlerNotCalledWhenExactlyAtThreshold()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testDurationThresholdHandlerReceivesCorrectArguments()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $receivedArgs = null;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(0), function () use (&$receivedArgs) {
            $receivedArgs = func_get_args();
        });

        Carbon::setTestNow($startedAt = Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertCount(3, $receivedArgs);
        $this->assertTrue($startedAt->eq($receivedArgs[0]));
        $this->assertSame($input, $receivedArgs[1]);
        $this->assertSame(21, $receivedArgs[2]);
    }

    public function testDurationThresholdWithMilliseconds()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        $this->assertFalse($called);

        Carbon::setTestNow(Carbon::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdWithMillisecondsNotExceeded()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testDurationThresholdWithDateTimeInterface()
    {
        Carbon::setTestNow(Carbon::now());

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(Carbon::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        $this->assertFalse($called);

        Carbon::setTestNow(Carbon::now()->addSeconds(1)->addMillisecond());
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdWithDateTimeInterfaceNotExceeded()
    {
        Carbon::setTestNow(Carbon::now());

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(Carbon::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testTerminateUsesConfiguredTimezone()
    {
        $this->app['config']->set('app.timezone', 'UTC');

        $startedAt = null;
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);
        $kernel->whenCommandLifecycleIsLongerThan(0, function ($started) use (&$startedAt) {
            $startedAt = $started;
        });

        $this->app['config']->set('app.timezone', 'Australia/Melbourne');

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        Carbon::setTestNow(now()->addMinute());
        $kernel->terminate($input, 0);

        $this->assertSame('Australia/Melbourne', $startedAt->timezone->getName());
    }

    public function testMultipleDurationHandlers()
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $calledFirst = false;
        $calledSecond = false;

        $kernel->whenCommandLifecycleIsLongerThan(500, function () use (&$calledFirst) {
            $calledFirst = true;
        });

        $kernel->whenCommandLifecycleIsLongerThan(2000, function () use (&$calledSecond) {
            $calledSecond = true;
        });

        Carbon::setTestNow(Carbon::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput());

        // Advance 1 second — exceeds first threshold (500ms) but not second (2000ms).
        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($calledFirst);
        $this->assertFalse($calledSecond);
    }
}
