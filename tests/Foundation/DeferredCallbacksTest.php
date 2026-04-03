<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\DeferredCallbacksTest;

use Hypervel\Console\Events\CommandFinished;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DeferredCallbacksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DeferredCallbacksTestState::reset();
    }

    public function testHttpTerminateRunsDeferredCallbacksForSuccessfulResponses()
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

    public function testHttpTerminateSkipsFailedResponsesUnlessAlwaysTrue()
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

    public function testCommandFinishedRunsDeferredCallbacksForSuccessfulExitCodes()
    {
        defer(function () {
            DeferredCallbacksTestState::record('normal');
        });

        defer(function () {
            DeferredCallbacksTestState::record('always');
        }, always: true);

        $this->app->make(Dispatcher::class)->dispatch(
            new CommandFinished('deferred-callbacks:test', new StringInput(''), new NullOutput(), 0)
        );

        $this->assertSame(['normal', 'always'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testCommandFinishedSkipsFailedExitCodesUnlessAlwaysTrue()
    {
        defer(function () {
            DeferredCallbacksTestState::record('normal');
        });

        defer(function () {
            DeferredCallbacksTestState::record('always');
        }, always: true);

        $this->app->make(Dispatcher::class)->dispatch(
            new CommandFinished('deferred-callbacks:test', new StringInput(''), new NullOutput(), 1)
        );

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testJobAttemptedRunsDeferredCallbacksForSuccessfulJobs()
    {
        defer(function () {
            DeferredCallbacksTestState::record('job');
        });

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturnFalse();

        $this->app->make(Dispatcher::class)->dispatch(
            new \Hypervel\Queue\Events\JobAttempted('database', $job, null)
        );

        $this->assertSame(['job'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testJobAttemptedSkipsFailedJobsAndSyncConnectionsUnlessAlwaysTrue()
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
            new \Hypervel\Queue\Events\JobAttempted('database', $failedJob, null)
        );

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);

        DeferredCallbacksTestState::reset();

        defer(function () {
            DeferredCallbacksTestState::record('sync');
        });

        $syncJob = m::mock(Job::class);
        $syncJob->shouldReceive('hasFailed')->never();

        $this->app->make(Dispatcher::class)->dispatch(
            new \Hypervel\Queue\Events\JobAttempted('sync', $syncJob, null)
        );

        $this->assertSame([], DeferredCallbacksTestState::$calls);
        $this->assertCount(1, $this->app->make(DeferredCallbackCollection::class));
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
