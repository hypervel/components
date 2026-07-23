<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Interruptible;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Job as QueueJobContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobPopped;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Events\JobReleasedAfterException;
use Hypervel\Queue\Events\Looping;
use Hypervel\Queue\Events\WorkerInterrupted;
use Hypervel\Queue\Events\WorkerPausing;
use Hypervel\Queue\Events\WorkerResuming;
use Hypervel\Queue\Events\WorkerStarting;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\MaxAttemptsExceededException;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Queue\WorkerStopReason;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class QueueWorkerTest extends TestCase
{
    protected EventDispatcher $events;

    protected ExceptionHandlerContract $exceptionHandler;

    protected ContainerContract $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = m::spy(EventDispatcher::class);
        $this->events->shouldReceive('hasListeners')->byDefault()->andReturn(true);
        $this->exceptionHandler = m::spy(ExceptionHandlerContract::class);
        $this->container = new Container;
        $this->container->instance(EventDispatcher::class, $this->events);
        $this->container->instance(ExceptionHandlerContract::class, $this->exceptionHandler);

        Container::setInstance($this->container);
    }

    public function testJobCanBeFired()
    {
        $worker = $this->getWorker('default', ['queue' => [$job = new WorkerFakeJob]]);
        $worker->runNextJob('default', 'queue', new WorkerOptions);
        $this->assertTrue($job->fired);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobPopping::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobPopped::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
    }

    public function testWorkerOptionsCoroutineContextIsScopedToJob()
    {
        CoroutineContext::set('queue.worker.test.previous', 'previous');

        $seen = [];
        $options = new WorkerOptions;
        $options->coroutineContext = [
            'queue.worker.test.previous' => 'seeded',
            'queue.worker.test.new' => 'fresh',
        ];

        $worker = $this->getWorker('default', ['queue' => [
            new WorkerFakeJob(function () use (&$seen) {
                $seen = [
                    CoroutineContext::get('queue.worker.test.previous'),
                    CoroutineContext::get('queue.worker.test.new'),
                ];
            }),
        ]]);

        $worker->runNextJob('default', 'queue', $options);

        $this->assertSame(['seeded', 'fresh'], $seen);
        $this->assertSame('previous', CoroutineContext::get('queue.worker.test.previous'));
        $this->assertFalse(CoroutineContext::has('queue.worker.test.new'));
    }

    public function testJobPoppingEvent()
    {
        $worker = $this->getWorker('default', ['queue' => [$job = new WorkerFakeJob]]);
        $worker->runNextJob('default', 'queue', new WorkerOptions);
        $this->assertTrue($job->fired);

        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) {
            return $event instanceof JobPopping
                && $event->connectionName === 'default'
                && $event->queue === 'queue';
        }))->once();
    }

    public function testJobPopEventsAreSkippedWhenNoListenersAreRegistered()
    {
        $this->events->shouldReceive('hasListeners')->with(JobPopping::class)->andReturn(false);
        $this->events->shouldReceive('hasListeners')->with(JobPopped::class)->andReturn(false);

        $worker = $this->getWorker('default', ['queue' => [$job = new WorkerFakeJob]]);
        $worker->runNextJob('default', 'queue', new WorkerOptions);

        $this->assertTrue($job->fired);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobPopping::class)]);
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobPopped::class)]);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
    }

    public function testWorkerCanMonitorTimeoutJobs(): void
    {
        $workerOptions = new WorkerOptions;
        $workerOptions->stopWhenEmpty = true;
        $workerOptions->monitorInterval = 5;

        $timer = new QueueWorkerTimer;
        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob,
        ]], timer: $timer);

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertSame([1], $timer->registered);
        $this->assertSame([5.0], $timer->timeouts);
        $this->assertSame([1], $timer->cleared);

        $this->assertTrue($firstJob->fired);

        $this->assertSame(0, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();
    }

    public function testDaemonClearsItsMonitorWhenTheLoopThrows(): void
    {
        $timer = new QueueWorkerTimer;
        $worker = $this->getWorker(
            'default',
            ['queue' => []],
            static fn (): never => throw new LoopBreakerException,
            $timer,
        );

        try {
            $worker->daemon('default', 'queue', new WorkerOptions);
            $this->fail('Expected the daemon loop to fail.');
        } catch (LoopBreakerException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([1], $timer->registered);
        $this->assertSame([1], $timer->cleared);
    }

    public function testTimeoutMonitorUnlocksWhenScanningThrows(): void
    {
        $timer = new QueueWorkerTimer;
        $worker = new MonitorFailureWorker(
            ...$this->workerDependencies(timer: $timer),
        );
        $worker->startMonitorForTest(new WorkerOptions);

        try {
            $timer->fire(1);
            $this->fail('Expected timeout scanning to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('timeout scan failed', $exception->getMessage());
        }

        $this->assertFalse($worker->monitorIsLockedForTest());
        $timer->fire(1);
        $this->assertSame(2, $worker->scanCount);
        $this->assertFalse($worker->monitorIsLockedForTest());
    }

    public function testKillDoesNotWaitForUnrelatedActiveJobs(): void
    {
        $worker = new KillTestWorker(...$this->workerDependencies());
        $worker->registerCoroutineJobForTest(new WorkerFakeJob, new WorkerOptions);

        try {
            $worker->kill(Worker::EXIT_SUCCESS, new WorkerOptions);
            $this->fail('Expected the process termination seam to throw.');
        } catch (WorkerKilledException $exception) {
            $this->assertSame(Worker::EXIT_SUCCESS, $exception->status);
        }

        $this->assertNull($worker->sleptFor);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(WorkerStopping::class))->once();
    }

    public function testWorkerCanWorkUntilQueueIsEmpty()
    {
        $workerOptions = new WorkerOptions;
        $workerOptions->stopWhenEmpty = true;

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob,
            $secondJob = new WorkerFakeJob,
        ]]);

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertTrue($secondJob->fired);

        $this->assertSame(0, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->twice();

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->twice();
    }

    public function testRecordingSleepStillYieldsToAConcurrencyLimitedJob(): void
    {
        $jobCompleted = false;
        $reported = null;
        $this->exceptionHandler->shouldReceive('report')->andReturnUsing(
            function (Throwable $exception) use (&$reported): void {
                $reported = $exception;
            },
        );
        $job = new WorkerFakeJob(function () use (&$jobCompleted): void {
            Coroutine::sleep(0.001);
            $jobCompleted = true;
        });
        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $options = new WorkerOptions;
        $options->concurrency = 1;
        $options->sleep = 0;
        $options->stopWhenEmpty = true;

        $status = $worker->daemon('default', 'queue', $options);

        $this->assertSame(Worker::EXIT_SUCCESS, $status);
        $this->assertTrue($job->fired);
        $this->assertNull($reported, $reported?->getMessage() ?? 'Unexpected job failure.');
        $this->assertTrue($jobCompleted);
        $this->assertSame(0, $worker->sleptFor);
    }

    public function testDaemonSleepsWhenQueueIsEmptyAtStartup()
    {
        // Regression: the idle branch must sleep even when $jobsProcessed is still 0
        // (i.e. on the very first loop iteration with an empty queue).
        $workerOptions = new WorkerOptions;
        $workerOptions->stopWhenEmpty = true;
        $workerOptions->sleep = 5;

        $worker = $this->getWorker('default', ['queue' => []]);

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertEquals(5, $worker->sleptFor);
        $this->assertSame(Worker::EXIT_SUCCESS, $status);
    }

    public function testWorkerStopsWhenMemoryExceeded()
    {
        $workerOptions = new WorkerOptions;

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob,
            $secondJob = new WorkerFakeJob,
        ]]);
        $worker->stopOnMemoryExceeded = true;

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertFalse($secondJob->fired);
        $this->assertSame(12, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
    }

    public function testWorkerMemoryExceededWhenMemoryIsZero()
    {
        $worker = new Worker(...$this->workerDependencies());
        $this->assertFalse($worker->memoryExceeded(0));
    }

    public function testWorkerMemoryExceededWhenMemoryGreaterThanZero()
    {
        $worker = new Worker(...$this->workerDependencies());
        $this->assertTrue($worker->memoryExceeded(1));
    }

    public function testWorkerMemoryExceededWhenMemoryIsNegative()
    {
        $worker = new Worker(...$this->workerDependencies());
        $this->assertFalse($worker->memoryExceeded(-1));
    }

    public function testDaemonShouldRunSkipsLoopingEventWhenNoListenersAreRegistered()
    {
        $events = m::mock(EventDispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(Looping::class)->andReturn(false);
        $events->shouldNotReceive('until');

        $worker = new LoopAwareWorker(
            new WorkerFakeManager('default', new WorkerFakeConnection('default', [])),
            $events,
            $this->exceptionHandler,
            fn () => false
        );

        $this->assertTrue($worker->daemonShouldRunForTest(new WorkerOptions, 'default', 'queue'));
    }

    public function testJobCanBeFiredBasedOnPriority()
    {
        $worker = $this->getWorker('default', [
            'high' => [$highJob = new WorkerFakeJob, $secondHighJob = new WorkerFakeJob],
            'low' => [$lowJob = new WorkerFakeJob],
        ]);

        $worker->runNextJob('default', 'high,low', new WorkerOptions);
        $this->assertTrue($highJob->fired);
        $this->assertFalse($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('default', 'high,low', new WorkerOptions);
        $this->assertTrue($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('default', 'high,low', new WorkerOptions);
        $this->assertTrue($lowJob->fired);
    }

    public function testExceptionIsReportedIfConnectionThrowsExceptionOnJobPop()
    {
        $worker = new InsomniacWorker(
            new WorkerFakeManager('default', new BrokenQueueConnection('default', $e = new RuntimeException)),
            $this->events,
            $this->exceptionHandler,
            function () {
                return false;
            }
        );

        $worker->runNextJob('default', 'queue', $this->workerOptions());

        $this->exceptionHandler->shouldHaveReceived('report')->with($e);
    }

    public function testWorkerSleepsWhenQueueIsEmpty()
    {
        $worker = $this->getWorker('default', ['queue' => []]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['sleep' => 5]));
        $this->assertEquals(5, $worker->sleptFor);
    }

    public function testJobIsReleasedOnException()
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function () use ($e) {
            throw $e;
        });

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['backoff' => 10]));

        $this->assertEquals(10, $job->releaseAfter);
        $this->assertFalse($job->deleted);
        $this->exceptionHandler->shouldHaveReceived('report')->with($e);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobProcessed::class)]);
    }

    public function testJobIsFailedIfExceptionHandlerSaysItShouldNotRetry(): void
    {
        $exception = new RuntimeException;
        $job = new WorkerFakeJob(static function () use ($exception): never {
            throw $exception;
        });
        $this->exceptionHandler = new ShouldntRetryExceptionHandler;

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['backoff' => 10]));

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertSame($exception, $job->failedWith);
        $this->events->shouldHaveReceived('dispatch')->with(m::on(
            static fn (object $event): bool => $event instanceof JobExceptionOccurred
                && $event->job === $job
                && $event->exception === $exception
                && $event->job->hasFailed(),
        ))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobReleasedAfterException::class)]);
    }

    public function testJobIsNotReleasedIfItHasExceededMaxAttempts()
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function ($job) use ($e) {
            // In normal use this would be incremented by being popped off the queue
            ++$job->attempts;

            throw $e;
        });

        $job->attempts = 1;

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['maxTries' => 1]));

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertEquals($e, $job->failedWith);
        $this->exceptionHandler->shouldHaveReceived('report')->with($e);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobProcessed::class)]);
    }

    public function testJobIsNotReleasedIfItHasExpired(): void
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function ($job) use ($e) {
            // In normal use this would be incremented by being popped off the queue
            ++$job->attempts;

            throw $e;
        });

        $job->retryUntil = now()->addSeconds(1)->getTimestamp();

        $job->attempts = 0;

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addSeconds(1)
        );

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions());

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertEquals($e, $job->failedWith);
        $this->exceptionHandler->shouldHaveReceived('report')->with($e);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobProcessed::class)]);
    }

    public function testJobIsFailedIfItHasAlreadyExceededMaxAttempts()
    {
        $job = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });

        $job->attempts = 2;

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['maxTries' => 1]));

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertInstanceOf(MaxAttemptsExceededException::class, $job->failedWith);
        $this->exceptionHandler->shouldHaveReceived('report')->with(m::type(MaxAttemptsExceededException::class));
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobProcessed::class)]);
    }

    public function testJobIsFailedIfItHasAlreadyExpired(): void
    {
        $job = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });

        $job->retryUntil = CarbonImmutable::now()->addSeconds(2)->getTimestamp();

        $job->attempts = 1;

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addSeconds(3)
        );

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions());

        $this->assertNull($job->releaseAfter);
        $this->assertTrue($job->deleted);
        $this->assertInstanceOf(MaxAttemptsExceededException::class, $job->failedWith);
        $this->exceptionHandler->shouldHaveReceived('report')->with(m::type(MaxAttemptsExceededException::class));
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobExceptionOccurred::class))->once();
        $this->events->shouldNotHaveReceived('dispatch', [m::type(JobProcessed::class)]);
    }

    public function testJobBasedMaxRetries()
    {
        $job = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });
        $job->attempts = 2;

        $job->maxTries = 10;

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['maxTries' => 1]));

        $this->assertFalse($job->deleted);
        $this->assertNull($job->failedWith);
    }

    public function testJobBasedFailedDelay()
    {
        $job = new WorkerFakeJob(function ($job) {
            throw new Exception('Something went wrong.');
        });

        $job->attempts = 1;
        $job->backoff = 10;

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['backoff' => 3, 'maxTries' => 0]));

        $this->assertEquals(10, $job->releaseAfter);
    }

    public function testJobRunsIfAppIsNotInMaintenanceMode()
    {
        $firstJob = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });

        $secondJob = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });

        $maintenanceFlags = [false, true];

        $maintenanceModeChecker = function () use (&$maintenanceFlags) {
            if ($maintenanceFlags) {
                return array_shift($maintenanceFlags);
            }

            throw new LoopBreakerException;
        };

        $worker = $this->getWorker('default', ['queue' => [$firstJob, $secondJob]], $maintenanceModeChecker);

        try {
            $worker->daemon('default', 'queue', $this->workerOptions());

            $this->fail('Expected LoopBreakerException to be thrown');
        } catch (LoopBreakerException) {
            $this->assertSame(1, $firstJob->attempts);

            $this->assertSame(0, $secondJob->attempts);
        }
    }

    public function testJobDoesNotFireIfDeleted()
    {
        $job = new WorkerFakeJob(function () {
            return true;
        });

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $job->delete();
        $worker->runNextJob('default', 'queue', $this->workerOptions());

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
        $this->assertFalse($job->hasFailed());
        $this->assertFalse($job->isReleased());
        $this->assertTrue($job->isDeleted());
    }

    public function testWorkerPicksJobUsingCustomCallbacks()
    {
        $worker = $this->getWorker('default', [
            'default' => [$defaultJob = new WorkerFakeJob],
            'custom' => [$customJob = new WorkerFakeJob],
        ]);

        $worker->runNextJob('default', 'default', new WorkerOptions);
        $worker->runNextJob('default', 'default', new WorkerOptions);

        $this->assertTrue($defaultJob->fired);
        $this->assertFalse($customJob->fired);

        $worker2 = $this->getWorker('default', [
            'default' => [$defaultJob = new WorkerFakeJob],
            'custom' => [$customJob = new WorkerFakeJob],
        ]);

        $worker2->setName('myworker');

        Worker::popUsing('myworker', function ($pop) {
            return $pop('custom');
        });

        $worker2->runNextJob('default', 'default', new WorkerOptions);
        $worker2->runNextJob('default', 'default', new WorkerOptions);

        $this->assertFalse($defaultJob->fired);
        $this->assertTrue($customJob->fired);

        Worker::popUsing('myworker', null);
    }

    public function testFlushStateResetsWorkerStaticState()
    {
        Worker::popUsing('myworker', function ($pop) {
            return $pop('custom');
        });
        Worker::$memoryExceededExitCode = 99;
        Worker::$restartable = false;
        Worker::$pausable = false;

        Worker::flushState();

        $this->assertNull(Worker::$memoryExceededExitCode);
        $this->assertTrue(Worker::$restartable);
        $this->assertTrue(Worker::$pausable);

        $worker = $this->getWorker('default', [
            'default' => [$defaultJob = new WorkerFakeJob],
            'custom' => [$customJob = new WorkerFakeJob],
        ]);
        $worker->setName('myworker');

        $worker->runNextJob('default', 'default', new WorkerOptions);

        $this->assertTrue($defaultJob->fired);
        $this->assertFalse($customJob->fired);
    }

    public function testWorkerStartingIsDispatched()
    {
        $workerOptions = new WorkerOptions;
        $workerOptions->stopWhenEmpty = true;

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob,
            $secondJob = new WorkerFakeJob,
        ]]);

        $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertTrue($secondJob->fired);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(WorkerStarting::class))->once();
    }

    public function testWorkerStoppingIsDispatched()
    {
        $workerOptions = new WorkerOptions;
        $workerOptions->stopWhenEmpty = true;

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob,
            $secondJob = new WorkerFakeJob,
        ]]);

        $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertTrue($secondJob->fired);

        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) use ($workerOptions) {
            return $event instanceof WorkerStopping
                && $event->status === 0
                && $event->workerOptions === $workerOptions
                && $event->reason === WorkerStopReason::QueueEmpty;
        }));
    }

    public function testWorkerInterruptionSignalDispatchesEventAndNotifiesRunningJobs(): void
    {
        $workerOptions = new WorkerOptions;
        $interruptible = new class implements Interruptible {
            public array $signals = [];

            public function interrupted(int $signal): void
            {
                $this->signals[] = $signal;
            }
        };

        $handler = m::mock(CallQueuedHandler::class);
        $handler->shouldReceive('getRunningCommand')->once()->andReturn($interruptible);

        $job = new WorkerFakeJob;
        $job->resolvedJob = $handler;

        $worker = $this->getWorker('default', ['queue' => []]);
        $worker->registerCoroutineJobForTest($job, $workerOptions);
        $worker->handleInterruptionSignalForTest(SIGTERM, 'default', 'queue', $workerOptions);

        $this->assertTrue($worker->shouldQuit);
        $this->assertSame([SIGTERM], $interruptible->signals);
        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) use ($workerOptions) {
            return $event instanceof WorkerInterrupted
                && $event->signal === SIGTERM
                && $event->connectionName === 'default'
                && $event->queue === 'queue'
                && $event->workerOptions === $workerOptions;
        }))->once();
    }

    public function testNotifyJobsOfSignalNotifiesEveryRunningInterruptibleJob(): void
    {
        $workerOptions = new WorkerOptions;
        $firstInterruptible = new WorkerInterruptibleJob;
        $secondInterruptible = new WorkerInterruptibleJob;

        $worker = $this->getWorker('default', ['queue' => []]);
        $worker->registerCoroutineJobForTest($this->workerJobWithRunningCommand($firstInterruptible), $workerOptions);
        $worker->registerCoroutineJobForTest($this->workerJobWithRunningCommand($secondInterruptible), $workerOptions);

        $worker->notifyJobsOfSignalForTest(SIGINT);

        $this->assertSame([SIGINT], $firstInterruptible->signals);
        $this->assertSame([SIGINT], $secondInterruptible->signals);
    }

    public function testNotifyJobsOfSignalIgnoresNonInterruptibleCommandsAndOtherHandlers(): void
    {
        $workerOptions = new WorkerOptions;
        $nonInterruptible = new WorkerNonInterruptibleJob;

        $worker = $this->getWorker('default', ['queue' => []]);
        $worker->registerCoroutineJobForTest($this->workerJobWithRunningCommand($nonInterruptible), $workerOptions);

        $otherHandlerJob = new WorkerFakeJob;
        $otherHandlerJob->resolvedJob = new class {
        };
        $worker->registerCoroutineJobForTest($otherHandlerJob, $workerOptions);

        $worker->notifyJobsOfSignalForTest(SIGQUIT);

        $this->assertSame([], $nonInterruptible->signals);
    }

    public function testNotifyJobsOfSignalIgnoresJobsWithoutResolvedHandlers(): void
    {
        $workerOptions = new WorkerOptions;
        $interruptible = new WorkerInterruptibleJob;

        $worker = $this->getWorker('default', ['queue' => []]);
        $worker->registerCoroutineJobForTest(new WorkerContractOnlyJob, $workerOptions);
        $worker->registerCoroutineJobForTest($this->workerJobWithRunningCommand($interruptible), $workerOptions);

        $worker->notifyJobsOfSignalForTest(SIGQUIT);

        $this->assertSame([SIGQUIT], $interruptible->signals);
    }

    public function testWorkerPauseAndResumeSignalEventsCarryWorkerContext(): void
    {
        $workerOptions = new WorkerOptions;
        $worker = $this->getWorker('default', ['queue' => []]);

        $worker->handlePauseSignalForTest('default', 'queue', $workerOptions);
        $this->assertTrue($worker->paused);

        $worker->handleResumeSignalForTest('default', 'queue', $workerOptions);
        $this->assertFalse($worker->paused);

        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) use ($workerOptions) {
            return $event instanceof WorkerPausing
                && $event->connectionName === 'default'
                && $event->queue === 'queue'
                && $event->workerOptions === $workerOptions;
        }))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) use ($workerOptions) {
            return $event instanceof WorkerResuming
                && $event->connectionName === 'default'
                && $event->queue === 'queue'
                && $event->workerOptions === $workerOptions;
        }))->once();
    }

    public function testJobReleasedEvent()
    {
        $e = new RuntimeException;

        $job = new WorkerFakeJob(function () use ($e) {
            throw $e;
        });

        $worker = $this->getWorker('default', ['queue' => [$job]]);
        $worker->runNextJob('default', 'queue', $this->workerOptions(['backoff' => 10]));

        $this->events->shouldHaveReceived('dispatch')->with(m::on(function ($event) use ($job) {
            return $event instanceof JobReleasedAfterException
                && $event->connectionName === 'default'
                && $event->job === $job
                && $event->backoff === 10;
        }))->once();
    }

    /**
     * Helpers...
     * @param mixed $connectionName
     * @param mixed $jobs
     */
    private function getWorker(
        $connectionName = 'default',
        $jobs = [],
        ?callable $isInMaintenanceMode = null,
        ?Timer $timer = null,
    ): InsomniacWorker {
        return new InsomniacWorker(
            ...$this->workerDependencies($connectionName, $jobs, $isInMaintenanceMode, $timer)
        );
    }

    private function workerDependencies(
        $connectionName = 'default',
        $jobs = [],
        ?callable $isInMaintenanceMode = null,
        ?Timer $timer = null,
    ): array {
        return [
            new WorkerFakeManager($connectionName, new WorkerFakeConnection($connectionName, $jobs)),
            $this->events,
            $this->exceptionHandler,
            $isInMaintenanceMode ?? function () {
                return false;
            },
            $timer,
        ];
    }

    private function workerOptions(array $overrides = [])
    {
        $options = new WorkerOptions;

        foreach ($overrides as $key => $value) {
            $options->{$key} = $value;
        }

        return $options;
    }

    private function workerJobWithRunningCommand(object $command): WorkerFakeJob
    {
        $handler = m::mock(CallQueuedHandler::class);
        $handler->shouldReceive('getRunningCommand')->once()->andReturn($command);

        $job = new WorkerFakeJob;
        $job->resolvedJob = $handler;

        return $job;
    }
}

/**
 * Fakes.
 */
class InsomniacWorker extends Worker
{
    public int|float|null $sleptFor = null;

    public bool $stopOnMemoryExceeded = false;

    public function sleep(float|int $seconds): void
    {
        $this->sleptFor = $seconds;
        parent::sleep(0);
    }

    public function stop(int $status = 0, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): int
    {
        return parent::stop($status, $options, $reason);
    }

    public function daemonShouldRun(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return ! ($this->isDownForMaintenance)();
    }

    public function memoryExceeded(float $memoryLimit): bool
    {
        return $this->stopOnMemoryExceeded;
    }

    public function handleInterruptionSignalForTest(int $signal, string $connectionName, string $queue, WorkerOptions $options): void
    {
        parent::handleInterruptionSignal($signal, $connectionName, $queue, $options);
    }

    public function handlePauseSignalForTest(string $connectionName, string $queue, WorkerOptions $options): void
    {
        parent::handlePauseSignal($connectionName, $queue, $options);
    }

    public function handleResumeSignalForTest(string $connectionName, string $queue, WorkerOptions $options): void
    {
        parent::handleResumeSignal($connectionName, $queue, $options);
    }

    public function notifyJobsOfSignalForTest(int $signal): void
    {
        parent::notifyJobsOfSignal($signal);
    }

    public function registerCoroutineJobForTest(Job $job, WorkerOptions $options): string
    {
        return parent::registerCoroutineJob($job, $options);
    }

    protected function supportsAsyncSignals(): bool
    {
        return false;
    }
}

class MonitorFailureWorker extends InsomniacWorker
{
    public int $scanCount = 0;

    public function startMonitorForTest(WorkerOptions $options): void
    {
        $this->monitorTimeoutJobs($options);
    }

    public function monitorIsLockedForTest(): bool
    {
        return $this->monitorLocked;
    }

    protected function terminateTimeoutJobs(WorkerOptions $options): void
    {
        if (++$this->scanCount === 1) {
            throw new RuntimeException('timeout scan failed');
        }
    }
}

class KillTestWorker extends InsomniacWorker
{
    protected function terminateProcess(int $status): never
    {
        throw new WorkerKilledException($status);
    }
}

class WorkerKilledException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Worker process terminated.');
    }
}

class LoopAwareWorker extends Worker
{
    public function daemonShouldRunForTest(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return parent::daemonShouldRun($options, $connectionName, $queue);
    }
}

class QueueWorkerTimer extends Timer
{
    /** @var float[] */
    public array $timeouts = [];

    /** @var array<int, callable> */
    public array $callbacks = [];

    /** @var int[] */
    public array $registered = [];

    /** @var int[] */
    public array $cleared = [];

    public function tick(
        float $timeout,
        callable $closure,
        string $identifier = Constants::WORKER_EXIT,
    ): int {
        $id = count($this->registered) + 1;
        $this->registered[] = $id;
        $this->timeouts[] = $timeout;
        $this->callbacks[$id] = $closure;

        return $id;
    }

    public function clear(int $id): void
    {
        $this->cleared[] = $id;
        unset($this->callbacks[$id]);
    }

    public function fire(int $id): void
    {
        ($this->callbacks[$id])();
    }
}

class WorkerFakeManager extends QueueManager
{
    public array $connections = [];

    public function __construct($name, $connection)
    {
        $this->connections[$name] = $connection;
    }

    public function connection(UnitEnum|string|null $name = null): Queue
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        return $this->connections[$name];
    }
}

trait HasQueue
{
    public function size(?string $queue = null): int
    {
        return count($this->jobs[$queue]);
    }

    public function pendingSize(?string $queue = null): int
    {
        return count($this->jobs[$queue]);
    }

    public function delayedSize(?string $queue = null): int
    {
        return 0;
    }

    public function reservedSize(?string $queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return null;
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $this->jobs[$queue][] = $job;

        return null;
    }

    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return null;
    }

    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    public function setConnectionName(string $name): static
    {
        return $this;
    }
}

class WorkerFakeConnection implements Queue
{
    use HasQueue;

    public string $connectionName;

    public array $jobs = [];

    public function __construct($connectionName, $jobs)
    {
        $this->connectionName = $connectionName;
        $this->jobs = $jobs;
    }

    public function pop(?string $queue = null): ?Job
    {
        return array_shift($this->jobs[$queue]);
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }
}

class BrokenQueueConnection implements Queue
{
    use HasQueue;

    public string $connectionName;

    public Throwable $exception;

    public function __construct($connectionName, $exception)
    {
        $this->connectionName = $connectionName;
        $this->exception = $exception;
    }

    public function pop(?string $queue = null): ?Job
    {
        throw $this->exception;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }
}

class ShouldntRetryExceptionHandler implements ExceptionHandlerContract
{
    public function report(Throwable $e): void
    {
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render(Request $request, Throwable $e): Response
    {
        return new Response;
    }

    public function renderForConsole(OutputInterface $output, Throwable $e): void
    {
    }

    public function afterResponse(callable $callback): void
    {
    }

    public function shouldStopRetries(Throwable $e): bool
    {
        return true;
    }
}

class WorkerFakeJob implements QueueJobContract
{
    public $id = '';

    public $fired = false;

    public $callback;

    public $deleted = false;

    public $releaseAfter;

    public $released = false;

    public $maxTries;

    public $maxExceptions = 0;

    public $shouldFailOnTimeout = false;

    public $uuid = 'fake-uu-id';

    public $backoff;

    public $retryUntil = 0;

    public $attempts = 0;

    public $failedWith;

    public $failed = false;

    public $connectionName = '';

    public $queue = '';

    public $rawBody = '';

    public mixed $resolvedJob = null;

    public function __construct($callback = null)
    {
        $this->callback = $callback ?: function () {
        };
    }

    public function getJobId(): int|string|null
    {
        return $this->id;
    }

    public function fire(): void
    {
        $this->fired = true;
        $this->callback->__invoke($this);
    }

    public function payload(): array
    {
        return [];
    }

    public function maxTries(): ?int
    {
        return $this->maxTries;
    }

    public function maxExceptions(): int
    {
        return $this->maxExceptions;
    }

    public function shouldFailOnTimeout(): bool
    {
        return $this->shouldFailOnTimeout;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function backoff(): ?int
    {
        return $this->backoff;
    }

    public function retryUntil(): int
    {
        return $this->retryUntil;
    }

    public function delete(): void
    {
        $this->deleted = true;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function release($delay = 0): void
    {
        $this->released = true;

        $this->releaseAfter = $delay;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isDeletedOrReleased(): bool
    {
        return $this->deleted || $this->released;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    public function fail($e = null): void
    {
        $this->markAsFailed();

        $this->delete();

        $this->failedWith = $e;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function getName(): string
    {
        return 'WorkerFakeJob';
    }

    public function resolveName(): string
    {
        return $this->getName();
    }

    public function resolveQueuedJobClass(): string
    {
        return $this->getName();
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getResolvedJob(): mixed
    {
        return $this->resolvedJob;
    }

    public function timeout(): int
    {
        return time() + 60;
    }
}

class WorkerContractOnlyJob implements QueueJobContract
{
    public function uuid(): ?string
    {
        return null;
    }

    public function getJobId(): int|string|null
    {
        return null;
    }

    public function payload(): array
    {
        return [];
    }

    public function fire(): void
    {
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
        return 0;
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
        return self::class;
    }

    public function resolveName(): string
    {
        return self::class;
    }

    public function resolveQueuedJobClass(): string
    {
        return self::class;
    }

    public function getConnectionName(): string
    {
        return '';
    }

    public function getQueue(): string
    {
        return '';
    }

    public function getRawBody(): string
    {
        return '';
    }
}

class WorkerInterruptibleJob implements Interruptible
{
    public array $signals = [];

    public function interrupted(int $signal): void
    {
        $this->signals[] = $signal;
    }
}

class WorkerNonInterruptibleJob
{
    public array $signals = [];

    public function interrupted(int $signal): void
    {
        $this->signals[] = $signal;
    }
}

class LoopBreakerException extends RuntimeException
{
}
