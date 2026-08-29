<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\DeferredCallbacksTest;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DeferredCallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DeferredCallbacksTestState::reset();
    }

    public function testHttpTerminateRunsDeferredCallbacksForSuccessfulResponses(): void
    {
        Route::get('/deferred-callbacks/success', function () {
            defer(function () {
                DeferredCallbacksTestState::record('success');
            });

            return response('ok');
        });

        $this->get('/deferred-callbacks/success')->assertOk();

        $this->assertSame(['success'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testHttpTerminateSkipsFailedResponsesUnlessAlwaysTrue(): void
    {
        Route::get('/deferred-callbacks/failure', function () {
            defer(function () {
                DeferredCallbacksTestState::record('normal');
            });

            defer(function () {
                DeferredCallbacksTestState::record('always');
            }, always: true);

            return response('fail', 500);
        });

        $this->get('/deferred-callbacks/failure')->assertStatus(500);

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testHttpRequestOwnsCallbacksRegisteredByNestedCommand(): void
    {
        $application = $this->createConsoleApplication();
        $application->addCommand(new NestedDeferredCommand);

        Route::get('/deferred-callbacks/command', function () use ($application) {
            $application->call('deferred-callbacks:nested');
            DeferredCallbacksTestState::record('after-command');

            return response('ok');
        });

        $this->get('/deferred-callbacks/command')->assertOk();

        $this->assertSame(['after-command', 'deferred'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testJobLifecycleOwnsCallbacksRegisteredByNestedCommand(): void
    {
        $application = $this->createConsoleApplication();
        $application->addCommand(new NestedDeferredCommand);
        $application->call('deferred-callbacks:nested');

        $this->assertSame([], DeferredCallbacksTestState::$calls);

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturnFalse();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $job, null)
        );

        $this->assertSame(['deferred'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testJobWithoutDeferredCallbacksDoesNotResolveTheCollection(): void
    {
        $job = m::mock(Job::class);
        $job->shouldNotReceive('hasFailed');

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $job, null)
        );

        $this->assertFalse(Container::getInstance()->resolvedScoped(DeferredCallbackCollection::class));
    }

    #[DataProvider('owningQueueConnections')]
    public function testJobAttemptedRunsDeferredCallbacksForSuccessfulJobs(string $connection): void
    {
        defer(function () {
            DeferredCallbacksTestState::record('job');
        });

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturnFalse();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted($connection, $job, null)
        );

        $this->assertSame(['job'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public static function owningQueueConnections(): array
    {
        return [
            'persistent' => ['database'],
            'deferred' => ['deferred'],
            'background' => ['background'],
        ];
    }

    public function testJobAttemptedSkipsFailedJobsAndSyncConnectionsUnlessAlwaysTrue(): void
    {
        defer(function () {
            DeferredCallbacksTestState::record('normal');
        });

        defer(function () {
            DeferredCallbacksTestState::record('always');
        }, always: true);

        $failedJob = m::mock(Job::class);
        $failedJob->shouldReceive('hasFailed')->andReturnTrue();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $failedJob, null)
        );

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);

        DeferredCallbacksTestState::reset();

        defer(function () {
            DeferredCallbacksTestState::record('sync');
        });

        $syncJob = m::mock(Job::class);
        $syncJob->shouldReceive('hasFailed')->never();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('sync', $syncJob, null)
        );

        $this->assertSame([], DeferredCallbacksTestState::$calls);
        $this->assertCount(1, $this->app->make(DeferredCallbackCollection::class));
    }

    private function createConsoleApplication(): ConsoleApplication
    {
        return new ConsoleApplication(
            $this->app,
            $this->app->make('events'),
            '1.0',
        );
    }
}

class NestedDeferredCommand extends Command
{
    protected ?string $name = 'deferred-callbacks:nested';

    public function handle(): int
    {
        defer(function (): void {
            DeferredCallbacksTestState::record('deferred');
        });

        return self::SUCCESS;
    }
}

class DeferredCallbacksTestState
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$calls = [];
    }

    public static function record(string $value): void
    {
        static::$calls[] = $value;
    }
}
