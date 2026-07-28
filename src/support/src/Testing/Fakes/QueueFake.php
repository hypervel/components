<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\Factory as FactoryContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @phpstan-type RawPushType array{payload: string, queue: ?string, options: array<array-key, mixed>}
 */
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
     * All of the payloads that have been raw pushed.
     *
     * @var list<RawPushType>
     */
    protected array $rawPushes = [];

    /**
     * All of the unique jobs that were pushed.
     */
    protected array $uniqueJobs = [];

    /**
     * All of the jobs that have been marked as reserved.
     */
    protected array $reserved = [];

    /**
     * Indicates if items should be serialized and restored when pushed to the queue.
     */
    protected bool $serializeAndRestore = false;

    /**
     * The callbacks that should be invoked before pushing a job.
     *
     * @var array<int, callable>
     */
    protected array $beforePushingCallbacks = [];

    /**
     * The callbacks that should be invoked after pushing a job.
     *
     * @var array<int, callable>
     */
    protected array $afterPushingCallbacks = [];

    /**
     * Create a new fake queue instance.
     */
    public function __construct(Container $app, array|string $jobsToFake = [], ?FactoryContract $queue = null)
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
    public function assertPushedTimes(string $job, int $times = 1): void
    {
        $count = $this->pushed($job)->count();

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf(
                "The expected [{$job}] job was pushed {$count} %s instead of {$times} %s.",
                Str::plural('time', $count),
                Str::plural('time', $times)
            )
        );
    }

    /**
     * Assert if a job was pushed exactly once.
     */
    public function assertPushedOnce(string $job): void
    {
        $this->assertPushedTimes($job, 1);
    }

    /**
     * Assert if a job was pushed based on a truth-test callback.
     */
    public function assertPushedOn(
        UnitEnum|string|null $queue,
        Closure|string $job,
        ?callable $callback = null,
    ): void {
        $queue = $this->normalizeQueue($queue);

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
            $this->pushed($job, $callback)->filter(fn ($job) => $job->chained === $chain)->isNotEmpty(),
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
     * Get all of the raw pushes matching a truth-test callback.
     *
     * @param null|Closure(string, ?string, array<array-key, mixed>): bool $callback
     * @return Collection<int, RawPushType>
     */
    public function pushedRaw(?Closure $callback = null): Collection
    {
        $callback ??= static fn () => true;

        return Collection::make($this->rawPushes)->filter(
            fn (array $data) => $callback($data['payload'], $data['queue'], $data['options'])
        );
    }

    /**
     * Get all of the jobs by listener class, passing an optional truth-test callback.
     */
    public function listenersPushed(string $listenerClass, ?callable $callback = null): Collection
    {
        if (! $this->hasPushed(CallQueuedListener::class)) {
            return Collection::make();
        }

        $collection = Collection::make($this->jobs[CallQueuedListener::class])
            ->filter(fn (array $data) => $data['job']->class === $listenerClass);

        if ($callback) {
            $collection = $collection->filter(
                fn (array $data) => $callback($data['job']->data[0] ?? null, $data['job'], $data['queue'], $data['data'])
            );
        }

        return $collection->pluck('job');
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
    public function size(UnitEnum|string|null $queue = null): int
    {
        return $this->pendingSize($queue)
            + $this->delayedSize($queue)
            + $this->reservedSize($queue);
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return $this->pendingJobs($queue)->count();
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->delayedJobs($queue)->count();
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return $this->reservedJobs($queue)->count();
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->allPendingJobs()
            ->whereStrict('queue', $this->normalizeQueue($queue))
            ->values();
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->allDelayedJobs()
            ->whereStrict('queue', $this->normalizeQueue($queue))
            ->values();
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(UnitEnum|string|null $queue = null): Collection
    {
        return $this->allReservedJobs()
            ->whereStrict('queue', $this->normalizeQueue($queue))
            ->values();
    }

    /**
     * Get all pending jobs across every queue.
     */
    public function allPendingJobs(): Collection
    {
        return $this->inspectJobs($this->pushedJobsWithDelay(false));
    }

    /**
     * Get all delayed jobs across every queue.
     */
    public function allDelayedJobs(): Collection
    {
        return $this->inspectJobs($this->pushedJobsWithDelay(true));
    }

    /**
     * Get all reserved jobs across every queue.
     */
    public function allReservedJobs(): Collection
    {
        return $this->inspectJobs($this->reserved);
    }

    /**
     * Map an array of jobs to a collection of inspected jobs.
     */
    protected function inspectJobs(array $jobs): Collection
    {
        return Collection::make($jobs)
            ->flatten(1)
            ->map(fn (array $data) => new InspectedJob(
                uuid: null,
                queue: $data['queue'],
                name: is_object($data['job'])
                    ? (method_exists($data['job'], 'displayName') ? $data['job']->displayName() : get_class($data['job']))
                    : $data['job'],
                attempts: 0,
                payload: [],
                createdAt: CarbonImmutable::createFromTimestamp($data['createdAt']),
            ));
    }

    /**
     * Get pushed jobs classified by their delay.
     */
    protected function pushedJobsWithDelay(bool $delayed): array
    {
        return Collection::make($this->jobs)
            ->map(fn (array $jobs) => array_values(array_filter(
                $jobs,
                fn (array $job) => ($job['delay'] !== null) === $delayed,
            )))
            ->filter()
            ->all();
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        return Collection::make($this->pushedJobsWithDelay(false))
            ->flatten(1)
            ->whereStrict('queue', $this->normalizeQueue($queue))
            ->min('createdAt');
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->enqueueUsing($job, $data, $queue, null);
    }

    /**
     * Push a job through the fake or its underlying queue.
     */
    protected function enqueueUsing(
        object|string $job,
        mixed $data,
        UnitEnum|string|null $queue,
        DateInterval|DateTimeInterface|int|null $delay,
    ): mixed {
        $queue = $this->normalizeQueue($queue);

        foreach ($this->beforePushingCallbacks as $callback) {
            $callback($job, $data, $queue);
        }

        if ($this->shouldFakeJob($job)) {
            if ($job instanceof Closure) {
                $job = CallQueuedClosure::create($job);
            }

            $this->jobs[is_object($job) ? get_class($job) : $job][] = [
                'job' => $this->serializeAndRestore ? $this->serializeAndRestoreJob($job) : $job,
                'queue' => $queue,
                'data' => $data,
                'createdAt' => CarbonImmutable::now()->getTimestamp(),
                'delay' => $delay,
            ];

            if ($job instanceof ShouldBeUnique) {
                $this->uniqueJobs[] = $job;
            }

            $result = null;
        } else {
            $connection = is_object($job) && isset($job->connection)
                ? $job->connection
                : null;

            $queueConnection = $this->queue->connection($connection);
            $result = $delay === null
                ? $queueConnection->push($job, $data, $queue)
                : $queueConnection->later($delay, $job, $data, $queue);
        }

        foreach ($this->afterPushingCallbacks as $callback) {
            $callback($job, $data, $queue);
        }

        return $result;
    }

    /**
     * Determine if a job should be faked or actually dispatched.
     */
    public function shouldFakeJob(object|string $job): bool
    {
        if ($this->shouldDispatchJob($job)) {
            return false;
        }

        if ($this->jobsToFake->isEmpty()) {
            return true;
        }

        return $this->jobsToFake->contains(
            fn ($jobToFake) => $job instanceof ((string) $jobToFake) || $job === (string) $jobToFake
        );
    }

    /**
     * Determine if a job should be pushed to the queue instead of faked.
     */
    protected function shouldDispatchJob(object|string $job): bool
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
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        $this->rawPushes[] = [
            'payload' => $payload,
            'queue' => $this->normalizeQueue($queue),
            'options' => $options,
        ];

        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
        UnitEnum|string|null $queue = null,
    ): mixed {
        return $this->enqueueUsing($job, $data, $queue, $delay);
    }

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(UnitEnum|string|null $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(
        UnitEnum|string|null $queue,
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
    ): mixed {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Record the given job as reserved.
     */
    public function reserve(object|string $job, UnitEnum|string|null $queue = null): void
    {
        $queue = $this->normalizeQueue($queue);

        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $this->reserved[is_object($job) ? get_class($job) : $job][] = [
            'job' => $this->serializeAndRestore ? $this->serializeAndRestoreJob($job) : $job,
            'queue' => $queue,
            'createdAt' => CarbonImmutable::now()->getTimestamp(),
        ];
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(UnitEnum|string|null $queue = null): ?Job
    {
        return null;
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        foreach ($jobs as $job) {
            is_object($job) && isset($job->delay)
                ? $this->later($job->delay, $job, $data, $queue)
                : $this->push($job, $data, $queue);
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
     * Get the payloads that were pushed raw.
     *
     * @return list<RawPushType>
     */
    public function rawPushes(): array
    {
        return $this->rawPushes;
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
     * Release the locks for all unique jobs that were pushed.
     */
    public function releaseUniqueJobLocks(): void
    {
        $lock = new UniqueLock($this->app->make(Cache::class));

        foreach ($this->uniqueJobs as $job) {
            $lock->release($job);
        }

        $this->uniqueJobs = [];
    }

    /**
     * Clear all of the reserved jobs.
     */
    public function clearReserved(): void
    {
        $this->reserved = [];
    }

    /**
     * Register a callback to be invoked before pushing a job.
     */
    public function beforePushing(callable $callback): static
    {
        $this->beforePushingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be invoked after pushing a job.
     */
    public function afterPushing(callable $callback): static
    {
        $this->afterPushingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Normalize a queue identifier.
     */
    protected function normalizeQueue(UnitEnum|string|null $queue): ?string
    {
        return $queue instanceof UnitEnum ? (string) enum_value($queue) : $queue;
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
