<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Factory as FactoryContract;
use Hypervel\Queue\Worker;
use Hypervel\Support\Testing\Fakes\QueueFake;

/**
 * @method static void before(mixed $callback)
 * @method static void after(mixed $callback)
 * @method static void exceptionOccurred(mixed $callback)
 * @method static void looping(mixed $callback)
 * @method static void failing(mixed $callback)
 * @method static void stopping(mixed $callback)
 * @method static bool connected(string|null $name = null)
 * @method static \Hypervel\Contracts\Queue\Queue connection(string|null $name = null)
 * @method static void extend(string $driver, \Closure $resolver)
 * @method static void addConnector(string $driver, \Closure $resolver)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static string getName(string|null $connection = null)
 * @method static \Hypervel\Contracts\Container\Container getApplication()
 * @method static \Hypervel\Queue\QueueManager setApplication(\Hypervel\Contracts\Container\Container $app)
 * @method static \Hypervel\Queue\QueueManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Queue\QueueManager addPoolable(string $driver)
 * @method static \Hypervel\Queue\QueueManager removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\Queue\QueueManager setPoolables(array $poolables)
 * @method static int size(string|null $queue = null)
 * @method static int pendingSize(string|null $queue = null)
 * @method static int delayedSize(string|null $queue = null)
 * @method static int reservedSize(string|null $queue = null)
 * @method static int|null creationTimeOfOldestPendingJob(string|null $queue = null)
 * @method static mixed push(object|string $job, mixed $data = '', string|null $queue = null)
 * @method static mixed pushOn(string|null $queue, object|string $job, mixed $data = '')
 * @method static mixed pushRaw(string $payload, string|null $queue = null, array $options = [])
 * @method static mixed later(\DateInterval|\DateTimeInterface|int $delay, object|string $job, mixed $data = '', string|null $queue = null)
 * @method static mixed laterOn(string|null $queue, \DateInterval|\DateTimeInterface|int $delay, object|string $job, mixed $data = '')
 * @method static mixed bulk(array $jobs, mixed $data = '', string|null $queue = null)
 * @method static \Hypervel\Contracts\Queue\Job|null pop(string|null $queue = null)
 * @method static string getConnectionName()
 * @method static \Hypervel\Contracts\Queue\Queue setConnectionName(string $name)
 * @method static mixed getJobTries(mixed $job)
 * @method static mixed getJobBackoff(mixed $job)
 * @method static mixed getJobExpiration(mixed $job)
 * @method static void createPayloadUsing(callable|null $callback)
 * @method static array getConfig()
 * @method static \Hypervel\Queue\Queue setConfig(array $config)
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Queue\Queue setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake except(array|string $jobsToBeQueued)
 * @method static void assertPushed(\Closure|string $job, callable|int|null $callback = null)
 * @method static void assertPushedOn(string|null $queue, \Closure|string $job, callable|null $callback = null)
 * @method static void assertPushedWithChain(string $job, array $expectedChain = [], callable|null $callback = null)
 * @method static void assertPushedWithoutChain(string $job, callable|null $callback = null)
 * @method static void assertClosurePushed(callable|int|null $callback = null)
 * @method static void assertClosureNotPushed(callable|null $callback = null)
 * @method static void assertNotPushed(\Closure|string $job, callable|null $callback = null)
 * @method static void assertCount(int $expectedCount)
 * @method static void assertNothingPushed()
 * @method static \Hypervel\Support\Collection pushed(string $job, callable|null $callback = null)
 * @method static bool hasPushed(string $job)
 * @method static bool shouldFakeJob(object $job)
 * @method static array pushedJobs()
 * @method static \Hypervel\Support\Testing\Fakes\QueueFake serializeAndRestore(bool $serializeAndRestore = true)
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
            ? static::getFacadeRoot()->queue
            : static::getFacadeRoot();

        return tap(new QueueFake(
            Container::getInstance(),
            $jobsToFake,
            $actualQueueManager
        ), function ($fake) {
            static::swap($fake);
        });
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return FactoryContract::class;
    }
}
