<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\QueuedClosure;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\QueueFailedOver;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
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

        $events->shouldReceive('hasListeners')->once()->with(QueueFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once();

        $redis->shouldReceive('push')->once()->andReturnUsing(
            fn () => throw new Exception('error')
        );

        $sync->shouldReceive('push')->once();

        $failover->push('some-job');
    }

    public function testCancellationDoesNotFailOverOrEmitAnEvent(): void
    {
        $manager = m::mock(QueueManager::class);
        $events = m::mock(DispatcherContract::class);
        $primary = m::mock(Queue::class);
        $cancellation = new CanceledException;
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary']);

        $manager->shouldReceive('connection')->once()->with('primary')->andReturn($primary);
        $manager->shouldNotReceive('connection')->with('secondary');
        $primary->shouldReceive('push')->once()->andThrow($cancellation);
        $events->shouldNotReceive('hasListeners', 'dispatch');

        try {
            $failover->push('job');
            $this->fail('Expected cancellation to escape the failover queue.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testCancellationAfterAnOrdinaryFailureRetainsFailureSuppressionState(): void
    {
        $events = new FailoverQueueFakeDispatcher;
        $manager = m::mock(QueueManager::class);
        $primary = m::mock(Queue::class);
        $secondary = m::mock(Queue::class);
        $cancellation = new CanceledException;
        $secondaryAttempts = 0;
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary', 'tertiary']);

        $manager->shouldReceive('connection')->twice()->with('primary')->andReturn($primary);
        $manager->shouldReceive('connection')->twice()->with('secondary')->andReturn($secondary);
        $manager->shouldNotReceive('connection')->with('tertiary');
        $primary->shouldReceive('push')->twice()->andThrow(new RuntimeException('Primary failed.'));
        $secondary->shouldReceive('push')->twice()->andReturnUsing(
            static function () use (&$secondaryAttempts, $cancellation): string {
                if ($secondaryAttempts++ === 0) {
                    throw $cancellation;
                }

                return 'secondary:ok';
            }
        );

        try {
            $failover->push('first');
            $this->fail('Expected cancellation to stop the first failover attempt.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertCount(1, $events->failedOverEvents);
        $this->assertSame('secondary:ok', $failover->push('second'));
        $this->assertCount(1, $events->failedOverEvents);
    }

    public function testCancellationFromAFailoverListenerStopsFallback(): void
    {
        $manager = m::mock(QueueManager::class);
        $events = m::mock(DispatcherContract::class);
        $primary = m::mock(Queue::class);
        $cancellation = new CanceledException;
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary']);

        $manager->shouldReceive('connection')->once()->with('primary')->andReturn($primary);
        $manager->shouldNotReceive('connection')->with('secondary');
        $primary->shouldReceive('push')->once()->andThrow(new RuntimeException('Primary failed.'));
        $events->shouldReceive('hasListeners')->once()->with(QueueFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::type(QueueFailedOver::class))
            ->andThrow($cancellation);

        try {
            $failover->push('job');
            $this->fail('Expected listener cancellation to stop failover.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBulkRespectsJobDelays(): void
    {
        $manager = m::mock(QueueManager::class);
        $failover = new FailoverQueue($manager, m::mock(DispatcherContract::class), ['sync']);
        $sync = m::mock(SyncQueue::class);

        $manager->shouldReceive('connection')->times(3)->with('sync')->andReturn($sync);
        $sync->shouldReceive('later')->once()->with(15, m::type(FailoverJobWithDelayAttribute::class), '', null);
        $sync->shouldReceive('later')->once()->with(30, m::type(FailoverJobWithDelayProperty::class), '', null);
        $sync->shouldReceive('push')->once()->with('regular-job', '', null);

        $failover->bulk([
            new FailoverJobWithDelayAttribute,
            new FailoverJobWithDelayProperty,
            'regular-job',
        ]);
    }

    public function testPushWaitsForTransactionsOnEveryConnectionBeforeFailingOver(): void
    {
        $events = new FailoverQueueFakeDispatcher;
        $primary = new FailoverQueueFailingConnection('primary');
        $secondary = new FailoverQueueSuccessfulConnection('secondary');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager([
                'primary' => $primary,
                'secondary' => $secondary,
            ]),
            $events,
            ['primary', 'secondary'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $failover->setContainer($container);

        $transactions->begin('first', 1);
        $transactions->begin('second', 1);

        $this->assertNull($failover->push('job'));
        $this->assertSame([], $primary->pushedJobs);
        $this->assertSame([], $secondary->pushedJobs);

        $transactions->commit('second', 1, 0);
        $this->assertSame([], $primary->pushedJobs);
        $this->assertSame([], $secondary->pushedJobs);

        $transactions->commit('first', 1, 0);
        $this->assertSame(['job'], $primary->pushedJobs);
        $this->assertSame(['job'], $secondary->pushedJobs);
        $this->assertCount(1, $events->failedOverEvents);
    }

    public function testPushReentersDeferralForATransactionOpenedByAnEarlierCommitCallback(): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['sync' => $connection]),
            new FailoverQueueFakeDispatcher,
            ['sync'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $failover->setContainer($container);

        $transactions->begin('initial', 1);
        $transactions->addCallback(
            static fn () => $transactions->begin('later', 1)
        );
        $failover->push('job');

        $transactions->commit('initial', 1, 0);

        $this->assertSame([], $connection->pushedJobs);

        $transactions->commit('later', 1, 0);

        $this->assertSame(['job'], $connection->pushedJobs);
    }

    public function testNestedCommitWaitsForTheRootTransaction(): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['sync' => $connection]),
            new FailoverQueueFakeDispatcher,
            ['sync'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $failover->setContainer($container);

        $transactions->begin('database', 1);
        $transactions->begin('database', 2);
        $failover->push('job');

        $transactions->commit('database', 2, 1);

        $this->assertSame([], $connection->pushedJobs);

        $transactions->commit('database', 1, 0);

        $this->assertSame(['job'], $connection->pushedJobs);
    }

    public function testAllConnectionFailuresPropagateFromTheCommitCallback(): void
    {
        $events = new FailoverQueueFakeDispatcher;
        $primary = new FailoverQueueFailingConnection('primary');
        $secondary = new FailoverQueueFailingConnection('secondary');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager([
                'primary' => $primary,
                'secondary' => $secondary,
            ]),
            $events,
            ['primary', 'secondary'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $failover->setContainer($container);

        $transactions->begin('database', 1);
        $failover->push('job');

        try {
            $transactions->commit('database', 1, 0);
            $this->fail('The final queue failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('secondary failed', $exception->getMessage());
        }

        $this->assertSame(['job'], $primary->pushedJobs);
        $this->assertSame(['job'], $secondary->pushedJobs);
        $this->assertCount(2, $events->failedOverEvents);
    }

    #[DataProvider('transactionCompletionOrders')]
    public function testRollbackOnEitherConnectionReleasesJobLocksWithoutAttemptingFailover(array $completionOrder): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['sync' => $connection]),
            new FailoverQueueFakeDispatcher,
            ['sync'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $cache = new CacheRepository(new ArrayStore);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $container->instance(CacheContract::class, $cache);
        $failover->setContainer($container);

        $job = new FailoverQueueUniqueDebouncedJob;
        $uniqueLock = new UniqueLock($cache);
        $debounceLock = new DebounceLock($cache);
        $this->assertTrue($uniqueLock->acquireForDispatch($job));
        $debounceLock->acquireForDispatch($job);

        $transactions->begin('first', 1);
        $transactions->begin('second', 1);
        $failover->push($job);

        $this->assertSame(
            [1, 1],
            $transactions->getPendingTransactions()
                ->map(static fn (DatabaseTransactionRecord $transaction): int => count($transaction->getCallbacksForRollback()))
                ->all()
        );

        foreach ($completionOrder as [$operation, $connectionName]) {
            if ($operation === 'commit') {
                $transactions->commit($connectionName, 1, 0);
            } else {
                $transactions->rollback($connectionName, 0);
            }
        }

        $this->assertSame([], $connection->pushedJobs);
        $this->assertTrue($uniqueLock->acquire($job));
        $this->assertNull($debounceLock->getCurrentOwner($job));
    }

    public static function transactionCompletionOrders(): array
    {
        return [
            'attached transaction commits first' => [[
                ['commit', 'second'],
                ['rollback', 'first'],
            ]],
            'other transaction rolls back first' => [[
                ['rollback', 'first'],
                ['commit', 'second'],
            ]],
        ];
    }

    public function testRollbackAttemptsEveryJobLockReleaseWhenOneFails(): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['sync' => $connection]),
            new FailoverQueueFakeDispatcher,
            ['sync'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $cache = m::mock(CacheContract::class);
        $lock = m::mock(LockContract::class);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $container->instance(CacheContract::class, $cache);
        $failover->setContainer($container);

        $job = new FailoverQueueUniqueDebouncedJob;
        $job->debounceOwner = 'debounce-owner';
        $failure = new RuntimeException('Unique lock release failed.');
        $uniqueKey = UniqueLock::getKey($job);
        $debounceKey = DebounceLock::getKey($job);

        DispatchLockContext::registerUnique($job, $cache, null, $uniqueKey, '');
        DispatchLockContext::registerDebounce($job, $cache, $debounceKey, $job->debounceOwner);

        $cache->shouldReceive('lock')->once()->with($uniqueKey)->andReturn($lock);
        $lock->shouldReceive('forceRelease')->once()->andThrow($failure);
        $cache->shouldReceive('get')->once()->with($debounceKey)->andReturn($job->debounceOwner);
        $cache->shouldReceive('forget')->once()->with($debounceKey)->andReturnTrue();
        $cache->shouldReceive('forget')->once()->with($debounceKey . ':first_dispatched_at')->andReturnTrue();

        $transactions->begin('database', 1);
        $failover->push($job);

        $caught = null;

        try {
            $transactions->rollback('database', 0);
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame([], $connection->pushedJobs);
    }

    public function testNestedRollbackReleasesJobLocksAndCancelsTheDispatch(): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['sync' => $connection]),
            new FailoverQueueFakeDispatcher,
            ['sync'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $cache = new CacheRepository(new ArrayStore);
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $container->instance(CacheContract::class, $cache);
        $failover->setContainer($container);

        $job = new FailoverQueueUniqueDebouncedJob;
        $uniqueLock = new UniqueLock($cache);
        $debounceLock = new DebounceLock($cache);
        $this->assertTrue($uniqueLock->acquireForDispatch($job));
        $debounceLock->acquireForDispatch($job);

        $transactions->begin('database', 1);
        $transactions->begin('database', 2);
        $failover->push($job);

        $transactions->rollback('database', 1);
        $transactions->commit('database', 1, 0);

        $this->assertSame([], $connection->pushedJobs);
        $this->assertTrue($uniqueLock->acquire($job));
        $this->assertNull($debounceLock->getCurrentOwner($job));
    }

    public function testRolledBackPushDoesNotResetFailureSuppressionState(): void
    {
        $events = new FailoverQueueFakeDispatcher;
        $primary = new FailoverQueueFailingConnection('primary');
        $secondary = new FailoverQueueSuccessfulConnection('secondary');
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager([
                'primary' => $primary,
                'secondary' => $secondary,
            ]),
            $events,
            ['primary', 'secondary'],
            true,
        );
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $failover->setContainer($container);

        $failover->push('first');
        $transactions->begin('database', 1);
        $failover->push('rolled-back');
        $transactions->rollback('database', 0);
        $failover->push('second');

        $this->assertSame(['first', 'second'], $secondary->pushedJobs);
        $this->assertCount(1, $events->failedOverEvents);
    }

    public function testJobPreferenceOverridesFailoverAfterCommitDefault(): void
    {
        $connection = new FailoverQueueSuccessfulConnection('sync');
        $manager = new FailoverQueueFakeManager(['sync' => $connection]);
        $transactions = new DatabaseTransactionsManager;
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $transactions->begin('database', 1);

        $afterCommit = new FailoverQueue($manager, new FailoverQueueFakeDispatcher, ['sync'], true);
        $afterCommit->setContainer($container);
        $afterCommit->push(new FailoverQueueBeforeCommitJob);

        $immediate = new FailoverQueue($manager, new FailoverQueueFakeDispatcher, ['sync']);
        $immediate->setContainer($container);
        $immediate->push(new FailoverQueueAfterCommitJob);

        $this->assertCount(1, $connection->pushedJobs);

        $transactions->commit('database', 1, 0);

        $this->assertCount(2, $connection->pushedJobs);
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

        $manager->shouldReceive('connection')->times(10)->with('redis')->andReturn($redis);
        $redis->shouldReceive('totalSize')->once()->withNoArgs()->andReturn(9);
        $redis->shouldReceive('totalPendingSize')->once()->withNoArgs()->andReturn(2);
        $redis->shouldReceive('totalDelayedSize')->once()->withNoArgs()->andReturn(3);
        $redis->shouldReceive('totalReservedSize')->once()->withNoArgs()->andReturn(4);
        $redis->shouldReceive('pendingJobs')->once()->with('emails')->andReturn($pending = new Collection(['pending']));
        $redis->shouldReceive('delayedJobs')->once()->with('emails')->andReturn($delayed = new Collection(['delayed']));
        $redis->shouldReceive('reservedJobs')->once()->with('emails')->andReturn($reserved = new Collection(['reserved']));
        $redis->shouldReceive('allPendingJobs')->once()->andReturn($allPending = new Collection(['all-pending']));
        $redis->shouldReceive('allDelayedJobs')->once()->andReturn($allDelayed = new Collection(['all-delayed']));
        $redis->shouldReceive('allReservedJobs')->once()->andReturn($allReserved = new Collection(['all-reserved']));

        $this->assertSame(9, $queue->totalSize());
        $this->assertSame(2, $queue->totalPendingSize());
        $this->assertSame(3, $queue->totalDelayedSize());
        $this->assertSame(4, $queue->totalReservedSize());
        $this->assertSame($pending, $queue->pendingJobs('emails'));
        $this->assertSame($delayed, $queue->delayedJobs('emails'));
        $this->assertSame($reserved, $queue->reservedJobs('emails'));
        $this->assertSame($allPending, $queue->allPendingJobs());
        $this->assertSame($allDelayed, $queue->allDelayedJobs());
        $this->assertSame($allReserved, $queue->allReservedJobs());
    }

    public function testPopForwardsTheQueueIndexToTheFirstConnection(): void
    {
        $manager = m::mock(QueueManager::class);
        $events = m::mock(DispatcherContract::class);
        $redis = m::mock(RedisQueue::class);
        $queue = new FailoverQueue($manager, $events, ['redis', 'sync']);

        $manager->shouldReceive('connection')->once()->with('redis')->andReturn($redis);
        $redis->shouldReceive('pop')->once()->with('emails', 2)->andReturnNull();

        $this->assertNull($queue->pop('emails', 2));
    }

    public function testPopDoesNotForwardTheQueueIndexToAnOrdinaryConnection(): void
    {
        $manager = m::mock(QueueManager::class);
        $events = m::mock(DispatcherContract::class);
        $sync = m::mock(SyncQueue::class);
        $queue = new FailoverQueue($manager, $events, ['sync']);

        $manager->shouldReceive('connection')->once()->with('sync')->andReturn($sync);
        $sync->shouldReceive('pop')->once()->with('emails')->andReturnNull();

        $this->assertNull($queue->pop('emails', 2));
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

    /** @var list<object|string> */
    public array $pushedJobs = [];

    public function __construct(
        protected string $name
    ) {
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $this->pushedJobs[] = $job;

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

    /** @var list<object|string> */
    public array $pushedJobs = [];

    public function __construct(
        protected string $name
    ) {
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $this->pushedJobs[] = $job;

        if (is_object($job)) {
            DispatchLockContext::accept($job);
        }

        return "{$this->name}:ok";
    }

    public function getConnectionName(): string
    {
        return $this->name;
    }
}

#[Delay(15)]
class FailoverJobWithDelayAttribute
{
}

class FailoverJobWithDelayProperty
{
    public int $delay = 30;
}

class FailoverQueueBeforeCommitJob
{
    public bool $afterCommit = false;
}

class FailoverQueueAfterCommitJob implements ShouldQueueAfterCommit
{
}

class FailoverQueueUniqueDebouncedJob implements ShouldBeUnique
{
    public string $debounceOwner = '';
}
