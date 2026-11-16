<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\DeferredCallbacksTest;

use Hypervel\Console\Application as ConsoleApplication;
use Hypervel\Console\Command;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Http\Response;
use Hypervel\Queue\Events\JobAttempted;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

class DeferredCallbacksTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DeferredCallbacksTestState::reset();
    }

    public function testHttpTerminateRunsDeferredCallbacksForSuccessfulResponses(): void
    {
        Route::get('/deferred-callbacks/success', function (): Response {
            defer(function (): void {
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
        Route::get('/deferred-callbacks/failure', function (): Response {
            defer(function (): void {
                DeferredCallbacksTestState::record('normal');
            });

            defer(function (): void {
                DeferredCallbacksTestState::record('always');
            }, always: true);

            return response('fail', 500);
        });

        $this->get('/deferred-callbacks/failure')->assertStatus(500);

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testCallbacksDeferredWithinADeferredCallbackAreInvoked(): void
    {
        $result = [];

        Route::get('/test', function () use (&$result): void {
            defer(function () use (&$result): void {
                $result[] = 'first';

                defer(function () use (&$result): void {
                    $result[] = 'second';
                });
            });
        });

        $this->get('/test');

        $this->assertSame(['first', 'second'], $result);
    }

    public function testHttpRequestOwnsCallbacksRegisteredByNestedCommand(): void
    {
        $application = $this->createConsoleApplication();
        $application->addCommand(new NestedDeferredCommand);

        Route::get('/deferred-callbacks/command', function () use ($application): Response {
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

        $job = Double::for(Job::class);
        $job->allows('hasFailed')->returns(false);

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $job, null)
        );

        $this->assertSame(['deferred'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    public function testCanceledJobAttemptSkipsEveryDeferredCallback(): void
    {
        defer(function (): void {
            DeferredCallbacksTestState::record('deferred');
        });
        defer(function (): void {
            DeferredCallbacksTestState::record('always');
        })->always();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', Double::for(Job::class), new CanceledException, canceled: true)
        );

        $this->assertSame([], DeferredCallbacksTestState::$calls);
    }

    public function testJobWithoutDeferredCallbacksDoesNotResolveTheCollection(): void
    {
        $job = Double::for(Job::class);
        $job->expects('hasFailed')->never();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $job, null)
        );

        $this->assertFalse(Container::getInstance()->resolvedScoped(DeferredCallbackCollection::class));
    }

    #[DataProvider('owningQueueConnections')]
    public function testJobAttemptedRunsDeferredCallbacksForSuccessfulJobs(string $connection): void
    {
        defer(function (): void {
            DeferredCallbacksTestState::record('job');
        });

        $job = Double::for(Job::class);
        $job->allows('hasFailed')->returns(false);

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted($connection, $job, null)
        );

        $this->assertSame(['job'], DeferredCallbacksTestState::$calls);
        $this->assertCount(0, $this->app->make(DeferredCallbackCollection::class));
    }

    /**
     * Provide queue connections that own deferred callbacks.
     */
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
        defer(function (): void {
            DeferredCallbacksTestState::record('normal');
        });

        defer(function (): void {
            DeferredCallbacksTestState::record('always');
        }, always: true);

        $failedJob = Double::for(Job::class);
        $failedJob->allows('hasFailed')->returns(true);

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('database', $failedJob, null)
        );

        $this->assertSame(['always'], DeferredCallbacksTestState::$calls);

        DeferredCallbacksTestState::reset();

        defer(function (): void {
            DeferredCallbacksTestState::record('sync');
        });

        $syncJob = Double::for(Job::class);
        $syncJob->expects('hasFailed')->never();

        $this->app->make(Dispatcher::class)->dispatch(
            new JobAttempted('sync', $syncJob, null)
        );

        $this->assertSame([], DeferredCallbacksTestState::$calls);
        $this->assertCount(1, $this->app->make(DeferredCallbackCollection::class));
    }

    /**
     * Create a console application for the test.
     */
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

    /**
     * Execute the console command.
     */
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

    /**
     * Reset the recorded callback calls.
     */
    public static function reset(): void
    {
        static::$calls = [];
    }

    /**
     * Record a callback call.
     */
    public static function record(string $value): void
    {
        static::$calls[] = $value;
    }
}
