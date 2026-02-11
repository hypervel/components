<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\Factory as FactoryContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;

class QueueFake extends QueueManager implements Fake, Queue
{
    use ReflectsClosures;

    /**
     * The original queue manager.
     */
    public ?FactoryContract $queue = null;

    /**
     * The job types that should be intercepted instead of pushed to the queue.
     */
    protected Collection $jobsToFake;

    /**
     * The job types that should be pushed to the queue and not intercepted.
     */
    protected Collection $jobsToBeQueued;

    /**
     * All of the jobs that have been pushed.
     */
    protected array $jobs = [];

    /**
     * Indicates if items should be serialized and restored when pushed to the queue.
     */
    protected bool $serializeAndRestore = false;

    /**
     * Create a new fake queue instance.
     */
    public function __construct(Container $app, array $jobsToFake = [], ?FactoryContract $queue = null)
    {
        parent::__construct($app);

        $this->jobsToFake = Collection::wrap($jobsToFake);
        $this->jobsToBeQueued = Collection::make();
        $this->queue = $queue;
    }

    /**
     * Specify the jobs that should be queued instead of faked.
     */
    public function except(array|string $jobsToBeQueued): static
    {
        $this->jobsToBeQueued = Collection::wrap($jobsToBeQueued)->merge($this->jobsToBeQueued);

        return $this;
    }

    /**
     * Assert if a job was pushed based on a truth-test callback.
     */
    public function assertPushed(Closure|string $job, callable|int|null $callback = null): void
    {
        if ($job instanceof Closure) {
            [$job, $callback] = [$this->firstClosureParameterType($job), $job];
        }

        if (is_numeric($callback)) {
            $this->assertPushedTimes($job, $callback);
            return;
        }

        PHPUnit::assertTrue(
            $this->pushed($job, $callback)->count() > 0,
            "The expected [{$job}] job was not pushed."
        );
    }

    /**
     * Assert if a job was pushed a number of times.
     */
    protected function assertPushedTimes(string $job, int $times = 1): void
    {
        $count = $this->pushed($job)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "The expected [{$job}] job was pushed {$count} times instead of {$times} times."
        );
    }

    /**
     * Assert if a job was pushed based on a truth-test callback.
     */
    public function assertPushedOn(?string $queue, Closure|string $job, ?callable $callback = null): void
    {
        if ($job instanceof Closure) {
            [$job, $callback] = [$this->firstClosureParameterType($job), $job];
        }

        $this->assertPushed($job, function ($job, $pushedQueue) use ($callback, $queue) {
            if ($pushedQueue !== $queue) {
                return false;
            }

            return $callback ? $callback(...func_get_args()) : true;
        });
    }

    /**
     * Assert if a job was pushed with chained jobs based on a truth-test callback.
     */
    public function assertPushedWithChain(string $job, array $expectedChain = [], ?callable $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->pushed($job, $callback)->isNotEmpty(),
            "The expected [{$job}] job was not pushed."
        );

        PHPUnit::assertTrue(
            Collection::make($expectedChain)->isNotEmpty(),
            'The expected chain can not be empty.'
        );

        $this->isChainOfObjects($expectedChain)
            ? $this->assertPushedWithChainOfObjects($job, $expectedChain, $callback)
            : $this->assertPushedWithChainOfClasses($job, $expectedChain, $callback);
    }

    /**
     * Assert if a job was pushed with an empty chain based on a truth-test callback.
     */
    public function assertPushedWithoutChain(string $job, ?callable $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->pushed($job, $callback)->isNotEmpty(),
            "The expected [{$job}] job was not pushed."
        );

        $this->assertPushedWithChainOfClasses($job, [], $callback);
    }

    /**
     * Assert if a job was pushed with chained jobs based on a truth-test callback.
     */
    protected function assertPushedWithChainOfObjects(string $job, array $expectedChain, ?callable $callback): void
    {
        $chain = Collection::make($expectedChain)->map(fn ($job) => serialize($job))->all();

        PHPUnit::assertTrue(
            $this->pushed($job, $callback)->filter(fn ($job) => $job->chained == $chain)->isNotEmpty(),
            'The expected chain was not pushed.'
        );
    }

    /**
     * Assert if a job was pushed with chained jobs based on a truth-test callback.
     */
    protected function assertPushedWithChainOfClasses(string $job, array $expectedChain, ?callable $callback): void
    {
        $matching = $this->pushed($job, $callback)->map->chained->map(function ($chain) {
            return Collection::make($chain)->map(function ($job) {
                return get_class(unserialize($job));
            });
        })->filter(function ($chain) use ($expectedChain) {
            return $chain->all() === $expectedChain;
        });

        PHPUnit::assertTrue(
            $matching->isNotEmpty(),
            'The expected chain was not pushed.'
        );
    }

    /**
     * Assert if a closure was pushed based on a truth-test callback.
     */
    public function assertClosurePushed(callable|int|null $callback = null): void
    {
        $this->assertPushed(CallQueuedClosure::class, $callback);
    }

    /**
     * Assert that a closure was not pushed based on a truth-test callback.
     */
    public function assertClosureNotPushed(?callable $callback = null): void
    {
        $this->assertNotPushed(CallQueuedClosure::class, $callback);
    }

    /**
     * Determine if the given chain is entirely composed of objects.
     */
    protected function isChainOfObjects(array $chain): bool
    {
        return ! Collection::make($chain)->contains(fn ($job) => ! is_object($job));
    }

    /**
     * Determine if a job was pushed based on a truth-test callback.
     */
    public function assertNotPushed(Closure|string $job, ?callable $callback = null): void
    {
        if ($job instanceof Closure) {
            [$job, $callback] = [$this->firstClosureParameterType($job), $job];
        }

        PHPUnit::assertCount(
            0,
            $this->pushed($job, $callback),
            "The unexpected [{$job}] job was pushed."
        );
    }

    /**
     * Assert the total count of jobs that were pushed.
     */
    public function assertCount(int $expectedCount): void
    {
        $actualCount = Collection::make($this->jobs)->flatten(1)->count();

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} jobs to be pushed, but found {$actualCount} instead."
        );
    }

    /**
     * Assert that no jobs were pushed.
     */
    public function assertNothingPushed(): void
    {
        $pushedJobs = implode("\n- ", array_keys($this->jobs));

        PHPUnit::assertEmpty($this->jobs, "The following jobs were pushed unexpectedly:\n\n- {$pushedJobs}\n");
    }

    /**
     * Get all of the jobs matching a truth-test callback.
     */
    public function pushed(string $job, ?callable $callback = null): Collection
    {
        if (! $this->hasPushed($job)) {
            return Collection::make();
        }

        $callback = $callback ?: fn () => true;

        return Collection::make($this->jobs[$job])->filter(
            fn ($data) => $callback($data['job'], $data['queue'], $data['data'])
        )->pluck('job');
    }

    /**
     * Determine if there are any stored jobs for a given class.
     */
    public function hasPushed(string $job): bool
    {
        return isset($this->jobs[$job]) && ! empty($this->jobs[$job]);
    }

    /**
     * Resolve a queue connection instance.
     */
    public function connection(mixed $value = null): Queue
    {
        return $this;
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return Collection::make($this->jobs)->flatten(1)->filter(
            fn ($job) => $job['queue'] === $queue
        )->count();
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return 0;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if ($this->shouldFakeJob($job)) {
            if ($job instanceof Closure) {
                $job = CallQueuedClosure::create($job);
            }

            $this->jobs[is_object($job) ? get_class($job) : $job][] = [
                'job' => $this->serializeAndRestore ? $this->serializeAndRestoreJob($job) : $job,
                'queue' => $queue,
                'data' => $data,
            ];
        } else {
            is_object($job) && isset($job->connection)
                ? $this->queue->connection($job->connection)->push($job, $data, $queue)
                : $this->queue->push($job, $data, $queue); // @phpstan-ignore-line
        }

        return null;
    }

    /**
     * Determine if a job should be faked or actually dispatched.
     */
    public function shouldFakeJob(object $job): bool
    {
        if ($this->shouldDispatchJob($job)) {
            return false;
        }

        if ($this->jobsToFake->isEmpty()) {
            return true;
        }

        return $this->jobsToFake->contains(
            fn ($jobToFake) => $job instanceof ((string) $jobToFake)
        );
    }

    /**
     * Determine if a job should be pushed to the queue instead of faked.
     */
    protected function shouldDispatchJob(object $job): bool
    {
        if ($this->jobsToBeQueued->isEmpty()) {
            return false;
        }

        return $this->jobsToBeQueued->contains(
            fn ($jobToQueue) => $job instanceof ((string) $jobToQueue)
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }

        return null;
    }

    /**
     * Get the jobs that have been pushed.
     */
    public function pushedJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Specify if jobs should be serialized and restored when being "pushed" to the queue.
     */
    public function serializeAndRestore(bool $serializeAndRestore = true): static
    {
        $this->serializeAndRestore = $serializeAndRestore;

        return $this;
    }

    /**
     * Serialize and unserialize the job to simulate the queueing process.
     */
    protected function serializeAndRestoreJob(mixed $job): mixed
    {
        return unserialize(serialize($job));
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return 'fake';
    }

    /**
     * Set the connection name for the queue.
     */
    public function setConnectionName(string $name): static
    {
        return $this;
    }

    /**
     * Override the QueueManager to prevent circular dependency.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()',
            static::class,
            $method
        ));
    }
}
