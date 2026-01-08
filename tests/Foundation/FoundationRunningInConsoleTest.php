<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hyperf\Context\Context;
use Hypervel\Console\Contracts\EventMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Foundation\Console\Contracts\Kernel as KernelContract;
use Hypervel\Queue\Contracts\Job as JobContract;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class FoundationRunningInConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset captured values between tests
        CaptureRunningInConsoleCommand::$capturedValue = null;
        NestedCallerCommand::$capturedValueBeforeCall = null;
        NestedCallerCommand::$capturedValueAfterCall = null;
        CaptureRunningInConsoleJob::$capturedValue = null;
    }

    protected function tearDown(): void
    {
        Context::destroy('__foundation.running_in_console');
        m::close();

        parent::tearDown();
    }

    public function testDefaultStateReturnsFalse(): void
    {
        $this->assertFalse($this->app->runningInConsole());
    }

    public function testMarkAsRunningInConsoleSetsStateToTrue(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        $this->app->markAsRunningInConsole();

        $this->assertTrue($this->app->runningInConsole());
    }

    public function testMarkAsRunningInConsoleIsIdempotent(): void
    {
        $this->app->markAsRunningInConsole();
        $this->app->markAsRunningInConsole();
        $this->app->markAsRunningInConsole();

        $this->assertTrue($this->app->runningInConsole());
    }

    public function testKernelHandleSetsRunningInConsole(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        Artisan::command('test:noop', fn () => 0);

        $kernel = $this->app->get(KernelContract::class);
        $kernel->handle(
            new ArrayInput(['command' => 'test:noop']),
            new BufferedOutput()
        );

        $this->assertTrue($this->app->runningInConsole());
    }

    public function testArtisanCallDoesNotSetRunningInConsole(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        Artisan::command('test:noop', fn () => 0);
        Artisan::call('test:noop');

        // Artisan::call() from non-console context should NOT set the flag
        $this->assertFalse($this->app->runningInConsole());
    }

    public function testCodeInsideCliCommandSeesRunningInConsoleTrue(): void
    {
        // This is the primary use case: code running inside a CLI command
        // (like a model's global scope) should see runningInConsole() = true

        $this->registerCaptureCommand();

        $kernel = $this->app->get(KernelContract::class);
        $kernel->handle(
            new ArrayInput(['command' => 'test:capture']),
            new BufferedOutput()
        );

        $this->assertTrue(CaptureRunningInConsoleCommand::$capturedValue);
    }

    public function testCodeInsideArtisanCallFromHttpSeesRunningInConsoleFalse(): void
    {
        // When Artisan::call() is used from HTTP context (e.g., controller),
        // code inside the command should see runningInConsole() = false

        $this->registerCaptureCommand();

        $kernel = $this->app->get(KernelContract::class);
        $kernel->call('test:capture');

        $this->assertFalse(CaptureRunningInConsoleCommand::$capturedValue);
    }

    public function testNestedCommandInheritsConsoleContext(): void
    {
        // When a CLI command calls another command via Artisan::call(),
        // the nested command should inherit the console context

        $this->registerCaptureCommand();
        $this->registerNestedCallerCommand();

        $kernel = $this->app->get(KernelContract::class);
        $kernel->handle(
            new ArrayInput(['command' => 'test:nested-caller']),
            new BufferedOutput()
        );

        // Parent command should see true
        $this->assertTrue(NestedCallerCommand::$capturedValueBeforeCall);

        // Nested command (called via Artisan::call) should also see true
        $this->assertTrue(CaptureRunningInConsoleCommand::$capturedValue);

        // Parent should still see true after the call
        $this->assertTrue(NestedCallerCommand::$capturedValueAfterCall);
    }

    public function testAppHelperReflectsRunningInConsoleState(): void
    {
        $this->assertFalse(app()->runningInConsole());

        $this->app->markAsRunningInConsole();

        $this->assertTrue(app()->runningInConsole());
    }

    public function testScheduledCommandInheritsConsoleContext(): void
    {
        // Scheduled commands are run via Kernel::call() from schedule:run,
        // which is itself invoked via Kernel::handle(). The scheduled command
        // should inherit the console context from the parent schedule:run command.

        $this->registerCaptureCommand();

        // Simulate the schedule:run command having set the console context
        // (this happens when `php artisan schedule:run` calls Kernel::handle())
        $this->app->markAsRunningInConsole();

        // Create a scheduled event that runs our capture command
        $event = new Event(m::mock(EventMutex::class), 'test:capture');

        // Run the scheduled event - this calls Kernel::call() internally
        $event->run($this->app);

        // The command should have inherited the console context
        $this->assertTrue(
            CaptureRunningInConsoleCommand::$capturedValue,
            'Scheduled command should inherit runningInConsole() = true from schedule:run'
        );
    }

    public function testQueueJobSeesRunningInConsoleFalse(): void
    {
        // Queue jobs run in the queue worker context, which does NOT go through
        // Kernel::handle(). Jobs should see runningInConsole() = false.

        // Ensure we're NOT in console context (simulating queue worker)
        $this->assertFalse($this->app->runningInConsole());

        // Create and fire a fake job
        $job = new CaptureRunningInConsoleJob();
        $job->fire();

        // The job should see runningInConsole() = false
        $this->assertFalse(
            CaptureRunningInConsoleJob::$capturedValue,
            'Queue job should see runningInConsole() = false'
        );
    }

    private function registerCaptureCommand(): void
    {
        $this->app->bind(CaptureRunningInConsoleCommand::class, CaptureRunningInConsoleCommand::class);
        $this->app->get(KernelContract::class)->registerCommand(CaptureRunningInConsoleCommand::class);
    }

    private function registerNestedCallerCommand(): void
    {
        $this->app->bind(NestedCallerCommand::class, NestedCallerCommand::class);
        $this->app->get(KernelContract::class)->registerCommand(NestedCallerCommand::class);
    }
}

/**
 * Test command that captures runningInConsole() value during execution.
 */
class CaptureRunningInConsoleCommand extends \Hypervel\Console\Command
{
    protected ?string $signature = 'test:capture';

    protected string $description = 'Captures runningInConsole value';

    public static ?bool $capturedValue = null;

    public function handle(): int
    {
        self::$capturedValue = app()->runningInConsole();

        return self::SUCCESS;
    }
}

/**
 * Test command that calls another command via Artisan::call().
 */
class NestedCallerCommand extends \Hypervel\Console\Command
{
    protected ?string $signature = 'test:nested-caller';

    protected string $description = 'Calls another command via Artisan::call';

    public static ?bool $capturedValueBeforeCall = null;

    public static ?bool $capturedValueAfterCall = null;

    public function handle(): int
    {
        self::$capturedValueBeforeCall = app()->runningInConsole();

        \Hypervel\Support\Facades\Artisan::call('test:capture');

        self::$capturedValueAfterCall = app()->runningInConsole();

        return self::SUCCESS;
    }
}

/**
 * Fake queue job that captures runningInConsole() value during execution.
 */
class CaptureRunningInConsoleJob implements JobContract
{
    public static ?bool $capturedValue = null;

    public function fire(): void
    {
        self::$capturedValue = app()->runningInConsole();
    }

    public function getJobId(): string|int|null
    {
        return 'test-job-id';
    }

    public function release(int $delay = 0): void
    {
    }

    public function isReleased(): bool
    {
        return false;
    }

    public function delete(): void
    {
    }

    public function isDeleted(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function markAsFailed(): void
    {
    }

    public function fail(?Throwable $e = null): void
    {
    }

    public function maxTries(): ?int
    {
        return null;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function timeout(): ?int
    {
        return null;
    }

    public function retryUntil(): ?int
    {
        return null;
    }

    public function getName(): string
    {
        return 'CaptureRunningInConsoleJob';
    }

    public function resolveName(): string
    {
        return self::class;
    }

    public function getConnectionName(): string
    {
        return 'sync';
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '{}';
    }

    public function uuid(): ?string
    {
        return 'test-uuid';
    }
}
