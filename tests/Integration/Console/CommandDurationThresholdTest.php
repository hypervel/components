<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Config;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class CommandDurationThresholdTest extends TestCase
{
    public function testItCanHandleExceedingCommandDuration(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 21);

        $this->assertTrue($called);
    }

    public function testItDoesntCallWhenExactlyThresholdDuration(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(1), function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertFalse($called);
    }

    public function testItProvidesArgsToHandler(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $args = null;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonInterval::seconds(0), function () use (&$args) {
            $args = func_get_args();
        });

        CarbonImmutable::setTestNow($startedAt = CarbonImmutable::now());
        $kernel->handle($input, new ConsoleOutput);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertCount(3, $args);
        $this->assertSame(CarbonImmutable::class, $args[0]::class);
        $this->assertTrue($startedAt->eq($args[0]));
        $this->assertSame($input, $args[1]);
        $this->assertSame(21, $args[2]);
    }

    public function testItCanExceedThresholdWhenSpecifyingDurationAsMilliseconds(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMilliseconds(1));
        $kernel->terminate($input, 21);

        $this->assertTrue($called);
    }

    public function testItCanStayUnderThresholdWhenSpecifyingDurationAsMilliseconds(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(1000, function () use (&$called) {
            $called = true;
        });

        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertFalse($called);
    }

    public function testItCanExceedThresholdWhenSpecifyingDurationAsDateTime(): void
    {
        $this->freezeSecond();

        $input = new StringInput('foo');
        $called = false;

        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $kernel->whenCommandLifecycleIsLongerThan(CarbonImmutable::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1)->addMillisecond());

        $kernel->terminate($input, 21);

        $this->assertTrue($called);
    }

    public function testItCanStayUnderThresholdWhenSpecifyingDurationAsDateTime(): void
    {
        $this->freezeSecond();
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');
        $called = false;
        $kernel->whenCommandLifecycleIsLongerThan(CarbonImmutable::now()->addSecond()->addMillisecond(), function () use (&$called) {
            $called = true;
        });

        $kernel->handle($input, new ConsoleOutput);

        $this->assertFalse($called);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(1));
        $kernel->terminate($input, 21);

        $this->assertFalse($called);
    }

    public function testItClearsStartTimeAfterHandlingCommand(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $input = new StringInput('foo');

        $this->assertNull($kernel->commandStartedAt());

        $kernel->handle($input, new ConsoleOutput);
        $startedAt = $kernel->commandStartedAt();
        $this->assertNotNull($startedAt);
        $this->assertSame(CarbonImmutable::class, $startedAt::class);

        $kernel->terminate($input, 21);
        $this->assertNull($kernel->commandStartedAt());
    }

    public function testUsesTheConfiguredDateTimezone(): void
    {
        Config::set('app.timezone', 'UTC');
        $startedAt = null;
        $kernel = $this->app->make(Kernel::class);
        $kernel->command('foo', fn () => null);
        $kernel->whenCommandLifecycleIsLongerThan(0, function (CarbonImmutable $started) use (&$startedAt, $kernel): void {
            $startedAt = $started;

            $this->assertSame($started, $kernel->commandStartedAt());
        });

        Config::set('app.timezone', 'Australia/Melbourne');
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $kernel->handle($input = new StringInput('foo'), new ConsoleOutput);

        CarbonImmutable::setTestNow(now()->addMinute());
        $kernel->terminate($input, 21);

        $this->assertSame(CarbonImmutable::class, $startedAt::class);
        $this->assertSame('Australia/Melbourne', $startedAt->timezone->getName());
    }

    public function testItHandlesCallingTerminateWithoutHandle(): void
    {
        $this->app->make(Kernel::class)->terminate(new StringInput('foo'), 21);

        // This is a placeholder just to show that the above did not throw an exception.
        $this->assertTrue(true);
    }
}
