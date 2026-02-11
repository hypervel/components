<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Event\Dispatcher as EventDispatcher;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Job as QueueJobContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Queue\Events\JobExceptionOccurred;
use Hypervel\Queue\Events\JobPopped;
use Hypervel\Queue\Events\JobPopping;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Exceptions\MaxAttemptsExceededException;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Carbon;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class QueueWorkerTest extends TestCase
{
    use RunTestsInCoroutine;

    protected EventDispatcher $events;

    protected ExceptionHandlerContract $exceptionHandler;

    protected ContainerContract $container;

    protected function setUp(): void
    {
        $this->events = m::spy(EventDispatcher::class);
        $this->exceptionHandler = m::spy(ExceptionHandlerContract::class);
        $this->container = new Container();
        $this->container->instance(EventDispatcher::class, $this->events);
        $this->container->instance(ExceptionHandlerContract::class, $this->exceptionHandler);

        ApplicationContext::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow();
    }

    public function testJobCanBeFired()
    {
        $worker = $this->getWorker('default', ['queue' => [$job = new WorkerFakeJob()]]);
        $worker->runNextJob('default', 'queue', new WorkerOptions());
        $this->assertTrue($job->fired);
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobPopping::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobPopped::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();
        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
    }

    public function testWorkerCanMonitorTimeoutJobs()
    {
        $workerOptions = new WorkerOptions();
        $workerOptions->stopWhenEmpty = true;

        $monitored = false;
        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob(),
        ]]);

        $status = $worker->daemon('default', 'queue', $workerOptions, function () use (&$monitored) {
            $monitored = true;
        });

        $this->assertTrue($monitored);

        $this->assertTrue($firstJob->fired);

        $this->assertSame(0, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();
    }

    public function testWorkerCanWorkUntilQueueIsEmpty()
    {
        $workerOptions = new WorkerOptions();
        $workerOptions->stopWhenEmpty = true;

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob(),
            $secondJob = new WorkerFakeJob(),
        ]]);

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertTrue($secondJob->fired);

        $this->assertSame(0, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->twice();

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->twice();
    }

    public function testWorkerStopsWhenMemoryExceeded()
    {
        $workerOptions = new WorkerOptions();

        $worker = $this->getWorker('default', ['queue' => [
            $firstJob = new WorkerFakeJob(),
            $secondJob = new WorkerFakeJob(),
        ]]);
        $worker->stopOnMemoryExceeded = true;

        $status = $worker->daemon('default', 'queue', $workerOptions);

        $this->assertTrue($firstJob->fired);
        $this->assertFalse($secondJob->fired);
        $this->assertSame(12, $status);

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessing::class))->once();

        $this->events->shouldHaveReceived('dispatch')->with(m::type(JobProcessed::class))->once();
    }

    public function testJobCanBeFiredBasedOnPriority()
    {
        $worker = $this->getWorker('default', [
            'high' => [$highJob = new WorkerFakeJob(), $secondHighJob = new WorkerFakeJob()],
            'low' => [$lowJob = new WorkerFakeJob()],
        ]);

        $worker->runNextJob('default', 'high,low', new WorkerOptions());
        $this->assertTrue($highJob->fired);
        $this->assertFalse($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('default', 'high,low', new WorkerOptions());
        $this->assertTrue($secondHighJob->fired);
        $this->assertFalse($lowJob->fired);

        $worker->runNextJob('default', 'high,low', new WorkerOptions());
        $this->assertTrue($lowJob->fired);
    }

    public function testExceptionIsReportedIfConnectionThrowsExceptionOnJobPop()
    {
        $worker = new InsomniacWorker(
            new WorkerFakeManager('default', new BrokenQueueConnection('default', $e = new RuntimeException())),
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
        $e = new RuntimeException();

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

    public function testJobIsNotReleasedIfItHasExceededMaxAttempts()
    {
        $e = new RuntimeException();

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

    public function testJobIsNotReleasedIfItHasExpired()
    {
        $e = new RuntimeException();

        $job = new WorkerFakeJob(function ($job) use ($e) {
            // In normal use this would be incremented by being popped off the queue
            ++$job->attempts;

            throw $e;
        });

        $job->retryUntil = now()->addSeconds(1)->getTimestamp();

        $job->attempts = 0;

        Carbon::setTestNow(
            Carbon::now()->addSeconds(1)
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

    public function testJobIsFailedIfItHasAlreadyExpired()
    {
        $job = new WorkerFakeJob(function ($job) {
            ++$job->attempts;
        });

        $job->retryUntil = Carbon::now()->addSeconds(2)->getTimestamp();

        $job->attempts = 1;

        Carbon::setTestNow(
            Carbon::now()->addSeconds(3)
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

            throw new LoopBreakerException();
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
            'default' => [$defaultJob = new WorkerFakeJob()],
            'custom' => [$customJob = new WorkerFakeJob()],
        ]);

        $worker->runNextJob('default', 'default', new WorkerOptions());
        $worker->runNextJob('default', 'default', new WorkerOptions());

        $this->assertTrue($defaultJob->fired);
        $this->assertFalse($customJob->fired);

        $worker2 = $this->getWorker('default', [
            'default' => [$defaultJob = new WorkerFakeJob()],
            'custom' => [$customJob = new WorkerFakeJob()],
        ]);

        $worker2->setName('myworker');

        Worker::popUsing('myworker', function ($pop) {
            return $pop('custom');
        });

        $worker2->runNextJob('default', 'default', new WorkerOptions());
        $worker2->runNextJob('default', 'default', new WorkerOptions());

        $this->assertFalse($defaultJob->fired);
        $this->assertTrue($customJob->fired);

        Worker::popUsing('myworker', null);
    }

    /**
     * Helpers...
     * @param mixed $connectionName
     * @param mixed $jobs
     */
    private function getWorker($connectionName = 'default', $jobs = [], ?callable $isInMaintenanceMode = null): InsomniacWorker
    {
        return new InsomniacWorker(
            ...$this->workerDependencies($connectionName, $jobs, $isInMaintenanceMode)
        );
    }

    private function workerDependencies($connectionName = 'default', $jobs = [], ?callable $isInMaintenanceMode = null): array
    {
        return [
            new WorkerFakeManager($connectionName, new WorkerFakeConnection($connectionName, $jobs)),
            $this->events,
            $this->exceptionHandler,
            $isInMaintenanceMode ?? function () {
                return false;
            },
        ];
    }

    private function workerOptions(array $overrides = [])
    {
        $options = new WorkerOptions();

        foreach ($overrides as $key => $value) {
            $options->{$key} = $value;
        }

        return $options;
    }
}

/**
 * Fakes.
 */
class InsomniacWorker extends Worker
{
    public $sleptFor;

    public $stopOnMemoryExceeded = false;

    public function sleep(float|int $seconds): void
    {
        $this->sleptFor = $seconds;
    }

    public function stop(int $status = 0, ?WorkerOptions $options = null): int
    {
        return $status;
    }

    public function daemonShouldRun(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return ! ($this->isDownForMaintenance)();
    }

    public function memoryExceeded(int $memoryLimit): bool
    {
        return $this->stopOnMemoryExceeded;
    }
}

class WorkerFakeManager extends QueueManager
{
    public array $connections = [];

    public function __construct($name, $connection)
    {
        $this->connections[$name] = $connection;
    }

    public function connection(?string $name = null): Queue
    {
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

    public function timeout(): int
    {
        return time() + 60;
    }
}

class LoopBreakerException extends RuntimeException
{
}
