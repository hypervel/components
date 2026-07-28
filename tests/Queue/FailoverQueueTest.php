<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Events\QueuedClosure;
use Hypervel\Queue\Events\QueueFailedOver;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use UnitEnum;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Support\enum_value;

class FailoverQueueTest extends TestCase
{
    public function testPushFailsOverOnException()
    {
        $failover = new FailoverQueue($queue = m::mock(QueueManager::class), $events = m::mock(DispatcherContract::class), [
            'redis',
            'sync',
        ]);

        $queue->shouldReceive('connection')->once()->with('redis')->andReturn(
            $redis = m::mock(RedisQueue::class),
        );

        $queue->shouldReceive('connection')->once()->with('sync')->andReturn(
            $sync = m::mock(SyncQueue::class),
        );

        $events->shouldReceive('dispatch')->once();

        $redis->shouldReceive('push')->once()->andReturnUsing(
            fn () => throw new Exception('error')
        );

        $sync->shouldReceive('push')->once();

        $failover->push('some-job');
    }

    public function testFailingQueueStateIsIsolatedBetweenCoroutines()
    {
        $events = new FailoverQueueFakeDispatcher;
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager([
                'redis' => new FailoverQueueFailingConnection('redis'),
                'sync' => new FailoverQueueSuccessfulConnection('sync'),
            ]),
            $events,
            ['redis', 'sync']
        );

        $results = parallel([
            'a' => function () use ($failover) {
                $failover->push('job-a-first');

                usleep(10000);

                $failover->push('job-a-second');

                return true;
            },
            'b' => function () use ($failover) {
                usleep(5000);

                $failover->push('job-b-first');

                return true;
            },
        ]);

        $this->assertSame(['a' => true, 'b' => true], $results);
        $this->assertSame(['job-a-first', 'job-b-first'], array_map(
            fn (QueueFailedOver $event) => $event->command,
            $events->failedOverEvents
        ));
    }

    public function testInspectionDelegatesToTheFirstConnection(): void
    {
        $manager = m::mock(QueueManager::class);
        $events = m::mock(DispatcherContract::class);
        $redis = m::mock(RedisQueue::class);
        $queue = new FailoverQueue($manager, $events, ['redis', 'sync']);

        $manager->shouldReceive('connection')->times(6)->with('redis')->andReturn($redis);
        $redis->shouldReceive('pendingJobs')->once()->with('emails')->andReturn($pending = new Collection(['pending']));
        $redis->shouldReceive('delayedJobs')->once()->with('emails')->andReturn($delayed = new Collection(['delayed']));
        $redis->shouldReceive('reservedJobs')->once()->with('emails')->andReturn($reserved = new Collection(['reserved']));
        $redis->shouldReceive('allPendingJobs')->once()->andReturn($allPending = new Collection(['all-pending']));
        $redis->shouldReceive('allDelayedJobs')->once()->andReturn($allDelayed = new Collection(['all-delayed']));
        $redis->shouldReceive('allReservedJobs')->once()->andReturn($allReserved = new Collection(['all-reserved']));

        $this->assertSame($pending, $queue->pendingJobs('emails'));
        $this->assertSame($delayed, $queue->delayedJobs('emails'));
        $this->assertSame($reserved, $queue->reservedJobs('emails'));
        $this->assertSame($allPending, $queue->allPendingJobs());
        $this->assertSame($allDelayed, $queue->allDelayedJobs());
        $this->assertSame($allReserved, $queue->allReservedJobs());
    }
}

class FailoverQueueFakeManager extends QueueManager
{
    /**
     * Create a new fake queue manager.
     *
     * @param array<string, Queue> $connections
     */
    public function __construct(
        protected array $connections
    ) {
    }

    public function connection(UnitEnum|string|null $name = null): Queue
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        return $this->connections[$name];
    }
}

class FailoverQueueFakeDispatcher implements DispatcherContract
{
    /**
     * @var list<QueueFailedOver>
     */
    public array $failedOverEvents = [];

    public function listen(array|Closure|QueuedClosure|string $events, array|object|string|null $listener = null): void
    {
    }

    public function observe(array|string $events, array|object|string $observer): void
    {
    }

    public function hasListeners(string $eventName): bool
    {
        return true;
    }

    public function subscribe(object|string $subscriber): void
    {
    }

    public function until(object|string $event, mixed $payload = []): mixed
    {
        return null;
    }

    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        if ($event instanceof QueueFailedOver) {
            $this->failedOverEvents[] = $event;
        }

        return $event;
    }

    public function push(string $event, mixed $payload = []): void
    {
    }

    public function flush(string $event): void
    {
    }

    public function forget(string $event): void
    {
    }

    public function forgetPushed(): void
    {
    }
}

trait FailoverQueueFakeQueue
{
    public function size(?string $queue = null): int
    {
        return 0;
    }

    public function pendingSize(?string $queue = null): int
    {
        return 0;
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
        return $this->push($job, $data, $queue);
    }

    public function laterOn(?string $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    public function pop(?string $queue = null): ?Job
    {
        return null;
    }

    public function setConnectionName(string $name): static
    {
        return $this;
    }
}

class FailoverQueueFailingConnection implements Queue
{
    use FailoverQueueFakeQueue;

    public function __construct(
        protected string $name
    ) {
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        throw new RuntimeException("{$this->name} failed");
    }

    public function getConnectionName(): string
    {
        return $this->name;
    }
}

class FailoverQueueSuccessfulConnection implements Queue
{
    use FailoverQueueFakeQueue;

    public function __construct(
        protected string $name
    ) {
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return "{$this->name}:ok";
    }

    public function getConnectionName(): string
    {
        return $this->name;
    }
}
