<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console\KernelTerminateTest;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Events\Terminating;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class KernelTerminateTest extends TestCase
{
    public function testTerminateDispatchesTerminatingEventAndAppTerminateInOrder(): void
    {
        $called = [];

        $this->app->make(Dispatcher::class)->listen(Terminating::class, function () use (&$called) {
            $called[] = 'terminating event';
        });
        $this->app->terminating(function () use (&$called) {
            $called[] = 'terminating callback';
        });

        $kernel = $this->app->make(KernelContract::class);
        $kernel->terminate(new StringInput(''), 0);

        $this->assertSame([
            'terminating event',
            'terminating callback',
        ], $called);
    }

    public function testTerminateDispatchesTerminatingEventEvenWithoutHandle(): void
    {
        // Calling terminate without a prior handle should not throw.
        $kernel = $this->app->make(KernelContract::class);
        $kernel->terminate(new StringInput(''), 0);

        // If we reach here without exception, the test passes.
        $this->assertTrue(true);
    }

    public function testCommandStartedAtIsNullBeforeHandle(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        $this->assertNull($kernel->commandStartedAt());
    }

    public function testCommandStartedAtIsSetAfterHandle(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $kernel->handle(new StringInput('foo'), new ConsoleOutput);

        $startedAt = $kernel->commandStartedAt();

        $this->assertNotNull($startedAt);
        $this->assertSame(CarbonImmutable::class, $startedAt::class);
    }

    public function testCommandStartedAtIsClearedAfterTerminate(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        CarbonImmutable::setTestNow(CarbonImmutable::now());

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);
        $this->assertNotNull($kernel->commandStartedAt());

        $kernel->terminate($input, 0);
        $this->assertNull($kernel->commandStartedAt());
    }

    public function testDurationThresholdHandlerCalledWhenExceeded(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdHandlerNotCalledWhenExactlyAtThreshold(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testDurationThresholdHandlerReceivesCorrectArguments(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $receivedArgs = null;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(0), function () use (&$receivedArgs) {
            $receivedArgs = func_get_args();
        });

        CarbonImmutable::setTestNow($startedAt = CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertCount(3, $receivedArgs);
        $this->assertSame(CarbonImmutable::class, $receivedArgs[0]::class);
        $this->assertTrue($startedAt->eq($receivedArgs[0]));
        $this->assertSame($input, $receivedArgs[1]);
        $this->assertSame(21, $receivedArgs[2]);
    }

    public function testDurationThresholdWithMilliseconds(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdWithMillisecondsNotExceeded(): void
    {
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testDurationThresholdWithDateTimeInterface(): void
    {
        $this->freezeSecond();

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonImmutable::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMillisecond());
        $kernel->terminate($input, 0);

        $this->assertTrue($called);
    }

    public function testDurationThresholdWithDateTimeInterfaceNotExceeded(): void
    {
        $this->freezeSecond();

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);

        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonImmutable::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertFalse($called);
    }

    public function testTerminateUsesConfiguredTimezone(): void
    {
        $this->app['config']->set('app.timezone', 'UTC');

        $startedAt = null;
        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);
        $kernel->whenCommandLifecycleIsLongerThan(0, function (CarbonImmutable $started) use (&$startedAt, $kernel): void {
            $startedAt = $started;

            $this->assertSame($started, $kernel->commandStartedAt());
        });

        $this->app['config']->set('app.timezone', 'Australia/Melbourne');

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        CarbonImmutable::setTestNow(now()->addMinute());
        $kernel->terminate($input, 0);

        $this->assertSame(CarbonImmutable::class, $startedAt::class);
        $this->assertSame('Australia/Melbourne', $startedAt->timezone->getName());
    }

    public function testMultipleDurationHandlers(): void
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

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);

        // Advance 1 second — exceeds first threshold (500ms) but not second (2000ms).
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 0);

        $this->assertTrue($calledFirst);
        $this->assertFalse($calledSecond);
    }

    public function testEventFailureDoesNotSkipApplicationOrDurationHandlers(): void
    {
        $calls = [];
        $eventException = new RuntimeException('event failed');
        $applicationException = new RuntimeException('application failed');
        $durationException = new RuntimeException('duration failed');

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);
        $this->app->make(Dispatcher::class)->listen(Terminating::class, function () use (&$calls, $eventException): void {
            $calls[] = 'event';

            throw $eventException;
        });
        $this->app->terminating(function () use (&$calls, $applicationException): void {
            $calls[] = 'application';

            throw $applicationException;
        });
        $kernel->whenCommandLifecycleIsLongerThan(0, function () use (&$calls, $durationException): void {
            $calls[] = 'first duration';

            throw $durationException;
        });
        $kernel->whenCommandLifecycleIsLongerThan(0, function () use (&$calls): void {
            $calls[] = 'second duration';
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

        try {
            $kernel->terminate($input, 0);

            self::fail('Expected the terminating event failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($eventException, $exception);
        }

        $this->assertSame(['event', 'application', 'first duration', 'second duration'], $calls);
        $this->assertNull($kernel->commandStartedAt());
    }

    public function testApplicationFailurePrecedesDurationHandlerFailure(): void
    {
        $calls = [];
        $applicationException = new RuntimeException('application failed');
        $durationException = new RuntimeException('duration failed');

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);
        $this->app->terminating(function () use (&$calls, $applicationException): void {
            $calls[] = 'application';

            throw $applicationException;
        });
        $kernel->whenCommandLifecycleIsLongerThan(0, function () use (&$calls, $durationException): void {
            $calls[] = 'duration';

            throw $durationException;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

        try {
            $kernel->terminate($input, 0);

            self::fail('Expected the application termination failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($applicationException, $exception);
        }

        $this->assertSame(['application', 'duration'], $calls);
        $this->assertNull($kernel->commandStartedAt());
    }

    public function testFirstDurationHandlerFailureDoesNotSkipLaterHandlers(): void
    {
        $calls = [];
        $firstException = new RuntimeException('first duration failed');
        $secondException = new RuntimeException('second duration failed');

        $kernel = $this->app->make(KernelContract::class);
        $kernel->command('foo', fn () => null);
        $kernel->whenCommandLifecycleIsLongerThan(0, function () use (&$calls, $firstException): void {
            $calls[] = 'first';

            throw $firstException;
        });
        $kernel->whenCommandLifecycleIsLongerThan(0, function () use (&$calls, $secondException): void {
            $calls[] = 'second';

            throw $secondException;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $input = new StringInput('foo');
        $kernel->handle($input, new ConsoleOutput);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());

        try {
            $kernel->terminate($input, 0);

            self::fail('Expected the first duration handler failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }

        $this->assertSame(['first', 'second'], $calls);
        $this->assertNull($kernel->commandStartedAt());
    }
}
