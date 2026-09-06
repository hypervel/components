<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Queue\Worker;
use Hypervel\Support\Testing\Fakes\QueueFake;

/**
 * @method static void addConnector(string $driver, \Closure $resolver)
 * @method static \Hypervel\Queue\QueueManager addPoolable(string $driver)
 * @method static void after(mixed $callback)
 * @method static void before(mixed $callback)
 * @method static bool connected(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\Contracts\Queue\Queue connection(\UnitEnum|string|null $name = null)
 * @method static void exceptionOccurred(mixed $callback)
 * @method static void extend(string $driver, \Closure $resolver)
 * @method static void failing(mixed $callback)
 * @method static \Hypervel\Contracts\Container\Container getApplication()
 * @method static string getDefaultDriver()
 * @method static string getName(string|null $connection = null)
 * @method static array getPausedQueues(string $connection, array $queues)
 * @method static array getPoolables()
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static bool isPaused(string $connection, string $queue)
 * @method static void looping(mixed $callback)
 * @method static void pause(string $connection, string $queue)
 * @method static void pauseFor(string $connection, string $queue, \DateInterval|\DateTimeInterface|int $ttl)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\Queue\QueueManager removePoolable(string $driver)
 * @method static string|null resolveConnectionFromQueueRoute(object $queueable)
 * @method static string|null resolveQueueFromQueueRoute(object $queueable)
 * @method static void resume(string $connection, string $queue)
 * @method static void route(array|string $class, \UnitEnum|string|null $queue = null, \UnitEnum|string|null $connection = null)
 * @method static \Hypervel\Queue\QueueManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static void setDefaultDriver(\UnitEnum|string $name)
 * @method static \Hypervel\Queue\QueueManager setPoolables(array $poolables)
 * @method static \Hypervel\Queue\QueueManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static void starting(mixed $callback)
 * @method static void stopping(mixed $callback)
 * @method static void withoutInterruptionPolling()
 * @method static mixed bulk(array $jobs, mixed $data = '', string|null $queue = null)
 * @method static int|null creationTimeOfOldestPendingJob(string|null $queue = null)
 * @method static int delayedSize(string|null $queue = null)
 * @method static string getConnectionName()
 * @method static mixed later(\DateInterval|\DateTimeInterface|int $delay, object|string $job, mixed $data = '', string|null $queue = null)
 * @method static mixed laterOn(string|null $queue, \DateInterval|\DateTimeInterface|int $delay, object|string $job, mixed $data = '')
 * @method static int pendingSize(string|null $queue = null)
 * @method static \Hypervel\Contracts\Queue\Job|null pop(string|null $queue = null)
 * @method static mixed push(object|string $job, mixed $data = '', string|null $queue = null)
 * @method static mixed pushOn(string|null $queue, object|string $job, mixed $data = '')
 * @method static mixed pushRaw(string $payload, string|null $queue = null, array $options = [])
 * @method static int reservedSize(string|null $queue = null)
 * @method static \Hypervel\Contracts\Queue\Queue setConnectionName(string $name)
 * @method static int size(string|null $queue = null)
 * @method static void createPayloadUsing(callable|null $callback)
 * @method static void flushState()
 * @method static array getConfig()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static mixed getJobBackoff(mixed $job)
 * @method static mixed getJobExpiration(mixed $job)
 * @method static mixed getJobTries(mixed $job)
 * @method static \Hypervel\Queue\Queue setAfterCommitDispatcher(null|\Closure $dispatcher)
 * @method static \Hypervel\Queue\Queue setConfig(array $config)
 * @method static \Hypervel\Queue\Queue setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake afterPushing(callable $callback)
 * @method static \Hypervel\Support\Collection allDelayedJobs()
 * @method static \Hypervel\Support\Collection allPendingJobs()
 * @method static \Hypervel\Support\Collection allReservedJobs()
 * @method static void assertClosureNotPushed(callable|null $callback = null)
 * @method static void assertClosurePushed(callable|int|null $callback = null)
 * @method static void assertCount(int $expectedCount)
 * @method static void assertNothingPushed()
 * @method static void assertNotPushed(\Closure|string $job, callable|null $callback = null)
 * @method static void assertPushed(\Closure|string $job, callable|int|null $callback = null)
 * @method static void assertPushedOn(\UnitEnum|string|null $queue, \Closure|string $job, callable|null $callback = null)
 * @method static void assertPushedOnce(string $job)
 * @method static void assertPushedTimes(string $job, int $times = 1)
 * @method static void assertPushedWithChain(string $job, array $expectedChain = [], callable|null $callback = null)
 * @method static void assertPushedWithoutChain(string $job, callable|null $callback = null)
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake beforePushing(callable $callback)
 * @method static void clearReserved()
 * @method static \Hypervel\Support\Collection delayedJobs(\UnitEnum|string|null $queue = null)
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake except(array|string $jobsToBeQueued)
 * @method static bool hasPushed(string $job)
 * @method static \Hypervel\Support\Collection listenersPushed(string $listenerClass, callable|null $callback = null)
 * @method static \Hypervel\Support\Collection pendingJobs(\UnitEnum|string|null $queue = null)
 * @method static \Hypervel\Support\Collection pushed(string $job, callable|null $callback = null)
 * @method static array pushedJobs()
 * @method static \Hypervel\Support\Collection<int, mixed> pushedRaw(null|\Closure $callback = null)
 * @method static array<int, mixed> rawPushes()
 * @method static void releaseUniqueJobLocks()
 * @method static void reserve(object|string $job, \UnitEnum|string|null $queue = null)
 * @method static \Hypervel\Support\Collection reservedJobs(\UnitEnum|string|null $queue = null)
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake serializeAndRestore(bool $serializeAndRestore = true)
 * @method static bool shouldFakeJob(object|string $job)
 * @method static int totalDelayedSize()
 * @method static int totalPendingSize()
 * @method static int totalReservedSize()
 * @method static int totalSize()
 *
 * @see \Hypervel\Queue\QueueManager
 * @see \Hypervel\Queue\Queue
 * @see \Hypervel\Support\Testing\Fakes\QueueFake
 */
class Queue extends Facade
{
    /**
     * Register a callback to be executed to pick jobs.
     */
    public static function popUsing(string $workerName, callable $callback): void
    {
        Worker::popUsing($workerName, $callback);
    }

    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(array|string $jobsToFake = []): QueueFake
    {
        $actualQueueManager = static::isFake()
            ? tap(static::getFacadeRoot(), fn ($fake) => $fake->releaseUniqueJobLocks())->queue
            : static::getFacadeRoot();
        /** @var ContainerContract $app */
        $app = static::getFacadeApplication();

        return tap(new QueueFake(
            $app,
            $jobsToFake,
            $actualQueueManager
        ), function ($fake) {
            static::swap($fake);
        });
    }

    /**
     * Replace the bound instance with a fake that fakes all jobs except the given jobs.
     */
    public static function fakeExcept(array|string $jobsToAllow): QueueFake
    {
        return static::fake()->except($jobsToAllow);
    }

    /**
     * Replace the bound instance with a fake during the given callable's execution.
     */
    public static function fakeFor(callable $callable, array $jobsToFake = []): mixed
    {
        $originalQueueManager = static::getFacadeRoot();

        static::fake($jobsToFake);

        try {
            return $callable();
        } finally {
            static::swap($originalQueueManager);
        }
    }

    /**
     * Replace the bound instance with a fake during the given callable's execution.
     */
    public static function fakeExceptFor(callable $callable, array $jobsToAllow = []): mixed
    {
        $originalQueueManager = static::getFacadeRoot();

        static::fakeExcept($jobsToAllow);

        try {
            return $callable();
        } finally {
            static::swap($originalQueueManager);
        }
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }
}
