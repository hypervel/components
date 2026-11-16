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
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueueAfterCommit;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Events\QueuedClosure;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Events\QueueFailedOver;
use Hypervel\Queue\FailoverQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\Collection;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Support\enum_value;

class FailoverQueueTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('forwardedQueueOperations')]
    public function testConnectionScopedForwardsApplyBeforeDelegating(string $method, array $arguments, array $expectedArguments, Collection|int|string|null $result): void
    {
        $routes = new QueueRoutes;
        $routes->forward(['default' => 'processing', 'reports' => 'processing', '0' => 'processing', 'processing' => 'archive'], connection: 'failover');
        Container::getInstance()->instance('queue.routes', $routes);
        $manager = Double::for(QueueManager::class);
        $redis = Double::for(RedisQueue::class);
        $manager->expects('connection')->with('redis')->returns($redis);
        $redis->expects($method)->with(...$expectedArguments)->returns($result);
        $queue = new FailoverQueue($manager, Double::for(DispatcherContract::class), ['redis']);
        $queue->setConnectionName('failover');

        $this->assertSame($result, $queue->{$method}(...$arguments));
    }

    /**
     * Provide each queue-name boundary owned by the failover driver.
     */
    public static function forwardedQueueOperations(): array
    {
        return [
            'push' => ['push', ['job', '', 'reports'], ['job', '', 'processing'], 'id'],
            'push enum' => ['push', ['job', '', IntegerQueueName::Zero], ['job', '', 'processing'], 'id'],
            'push without queue' => ['push', ['job'], ['job'], 'id'],
            'pushRaw' => ['pushRaw', ['payload', 'reports'], ['payload', 'processing'], 'id'],
            'later' => ['later', [10, 'job', '', 'reports'], [10, 'job', '', 'processing'], 'id'],
            'pop' => ['pop', ['reports', 2], ['processing', 2], null],
            'size' => ['size', ['reports'], ['processing'], 7],
            'size enum' => ['size', [IntegerQueueName::Zero], ['processing'], 7],
            'pendingSize' => ['pendingSize', ['reports'], ['processing'], 7],
            'delayedSize' => ['delayedSize', ['reports'], ['processing'], 7],
            'reservedSize' => ['reservedSize', ['reports'], ['processing'], 7],
            'pendingJobs' => ['pendingJobs', ['reports'], ['processing'], new Collection],
            'delayedJobs' => ['delayedJobs', ['reports'], ['processing'], new Collection],
            'reservedJobs' => ['reservedJobs', ['reports'], ['processing'], new Collection],
            'oldest pending' => ['creationTimeOfOldestPendingJob', ['reports'], ['processing'], 7],
        ];
    }

    #[DataProvider('failedConnectionNames')]
    public function testPushFailsOverOnException(UnitEnum|string|null $connection, ?string $expectedName): void
    {
        $queue = Double::for(QueueManager::class);
        $events = new EventDispatcher;
        $failedOver = [];
        $events->listen(QueueFailedOver::class, function (QueueFailedOver $event) use (&$failedOver): void {
            $failedOver[] = $event;
        });
        $failover = new FailoverQueue($queue, $events, [
            $connection,
            'sync',
        ]);

        $redis = Double::for(RedisQueue::class);
        $queue->expects('connection')->with($connection)->returns($redis);

        $sync = Double::for(SyncQueue::class);
        $queue->expects('connection')->with('sync')->returns($sync);

        $redis->expects('push')->resolves(
            fn (): never => throw new Exception('error')
        );

        $sync->expects('push');

        $failover->push('some-job');

        $this->assertCount(1, $failedOver);
        $this->assertSame($expectedName, $failedOver[0]->connectionName);
        $this->assertSame('some-job', $failedOver[0]->command);
        $this->assertSame('error', $failedOver[0]->exception->getMessage());
    }

    /**
     * Provide supported failover connection names.
     */
    public static function failedConnectionNames(): array
    {
        return [
            'named connection' => ['redis', 'redis'],
            'enum connection' => [IntegerQueueName::Zero, '0'],
            'default connection' => [null, null],
        ];
    }

    public function testReplacementQueueReportsItsOwnFirstFailure(): void
    {
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $primary = Double::for(Queue::class);
        $secondary = Double::for(Queue::class);
        $primary->allows('push')->throws(new RuntimeException('Primary unavailable.'));
        $secondary->allows('push')->returns('job-id');
        $manager->allows('connection')->with('primary')->returns($primary);
        $manager->allows('connection')->with('secondary')->returns($secondary);
        $events->allows('hasListeners')->with(QueueFailedOver::class)->returns(true);
        $events->expects('dispatch')->times(2)->with(Argument::type(QueueFailedOver::class));

        $queue = new FailoverQueue($manager, $events, ['primary', 'secondary']);
        $this->assertSame('job-id', $queue->push('job'));
        unset($queue);

        $replacement = new FailoverQueue($manager, $events, ['primary', 'secondary']);
        $this->assertSame('job-id', $replacement->push('job'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCancellationDoesNotFailOverOrEmitAnEvent(bool $cleanupThrows): void
    {
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $primary = Double::for(Queue::class);
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Queue cleanup failed.', previous: $cancellation);
        }
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary']);

        $manager->expects('connection')->with('primary')->returns($primary);
        $manager->expects('connection')->never()->with('secondary');
        $primary->expects('push')->throws($cancellation);
        $events->expects('hasListeners')->never();
        $events->expects('dispatch')->never();

        try {
            $failover->push('job');
            $this->fail('Expected cancellation to escape the failover queue.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCancellationAfterAnOrdinaryFailureRetainsFailureSuppressionState(bool $cleanupThrows): void
    {
        $events = new FailoverQueueFakeDispatcher;
        $manager = Double::for(QueueManager::class);
        $primary = Double::for(Queue::class);
        $secondary = Double::for(Queue::class);
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Queue cleanup failed.', previous: $cancellation);
        }
        $secondaryAttempts = 0;
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary', 'tertiary']);

        $manager->expects('connection')->times(2)->with('primary')->returns($primary);
        $manager->expects('connection')->times(2)->with('secondary')->returns($secondary);
        $manager->expects('connection')->never()->with('tertiary');
        $primary->expects('push')->times(2)->throws(new RuntimeException('Primary failed.'));
        $secondary->expects('push')->times(2)->resolves(
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
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertCount(1, $events->failedOverEvents);
        $this->assertSame('secondary:ok', $failover->push('second'));
        $this->assertCount(1, $events->failedOverEvents);
    }

    public function testCancellationFromAFailoverListenerStopsFallback(): void
    {
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $primary = Double::for(Queue::class);
        $cancellation = new CanceledException;
        $failover = new FailoverQueue($manager, $events, ['primary', 'secondary']);

        $manager->expects('connection')->with('primary')->returns($primary);
        $manager->expects('connection')->never()->with('secondary');
        $primary->expects('push')->throws(new RuntimeException('Primary failed.'));
        $events->expects('hasListeners')->with(QueueFailedOver::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::type(QueueFailedOver::class))
            ->throws($cancellation);

        try {
            $failover->push('job');
            $this->fail('Expected listener cancellation to stop failover.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testBulkRespectsJobDelays(): void
    {
        $manager = Double::for(QueueManager::class);
        $failover = new FailoverQueue($manager, Double::for(DispatcherContract::class), ['sync']);
        $sync = Double::for(SyncQueue::class);

        $manager->expects('connection')->times(3)->with('sync')->returns($sync);
        $sync->expects('later')->with(15, Argument::type(FailoverJobWithDelayAttribute::class), '', null);
        $sync->expects('later')->with(30, Argument::type(FailoverJobWithDelayProperty::class), '', null);
        $sync->expects('push')->with('regular-job', '', null);

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

    public function testInlineAfterCommitChildRetainsLockOwnershipForFallback(): void
    {
        $container = Container::getInstance();
        $container->instance('db.transactions', new DatabaseTransactionsManager);
        $events = new EventDispatcher($container);
        $container->instance(DispatcherContract::class, $events);
        $container->instance('events', $events);
        $failure = new RuntimeException('Scheduling failed before acceptance.');
        $first = new class($failure) extends SyncQueue {
            /**
             * Configure an after-commit child whose execution cannot start.
             */
            public function __construct(private RuntimeException $failure)
            {
                parent::__construct(true);
            }

            /**
             * Fail before the queue can accept the dispatch.
             */
            protected function executePayload(string $payload, ?string $queue = null): int
            {
                throw $this->failure;
            }
        };
        $first->setContainer($container)->setConnectionName('first');
        $second = Double::for(Queue::class);
        $job = new FailoverQueueUniqueDebouncedJob;
        $lock = new UniqueLock(new CacheRepository(new ArrayStore));
        $this->assertTrue($lock->acquireForDispatch($job));
        $second->expects('push')->with($job)->resolves(function ($job) use ($lock): string {
            $this->assertTrue(DispatchLockContext::has($job));
            $this->assertFalse($lock->acquire($job));
            DispatchLockContext::accept($job);

            return 'accepted';
        });
        $events->listen(QueueFailedOver::class, function (QueueFailedOver $event) use ($failure): void {
            $this->assertSame($failure, $event->exception);
        });
        $failover = new FailoverQueue(
            new FailoverQueueFakeManager(['first' => $first, 'second' => $second]),
            $events,
            ['first', 'second'],
        );
        $failover->setContainer($container);

        $this->assertSame('accepted', $failover->push($job));
        $this->assertFalse(DispatchLockContext::has($job));
        $this->assertFalse($lock->acquire($job));
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
        $cache = Double::for(CacheContract::class, LockProvider::class);
        $lock = Double::for(LockContract::class);
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

        $cache->expects('lock')->with($uniqueKey)->returns($lock);
        $lock->expects('forceRelease')->throws($failure);
        $cache->expects('get')->with($debounceKey)->returns($job->debounceOwner);
        $cache->expects('forget')->with($debounceKey)->returns(true);
        $cache->expects('forget')->with($debounceKey . ':first_dispatched_at')->returns(true);

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

    public function testFailingQueueStateIsIsolatedBetweenCoroutines(): void
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
            'a' => function () use ($failover): bool {
                $failover->push('job-a-first');

                usleep(10000);

                $failover->push('job-a-second');

                return true;
            },
            'b' => function () use ($failover): bool {
                usleep(5000);

                $failover->push('job-b-first');

                return true;
            },
        ]);

        $this->assertSame(['a' => true, 'b' => true], $results);
        $this->assertSame(['job-a-first', 'job-b-first'], array_map(
            fn (QueueFailedOver $event): object|string => $event->command,
            $events->failedOverEvents
        ));
    }

    public function testInspectionDelegatesToTheFirstConnection(): void
    {
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $redis = Double::for(RedisQueue::class);
        $queue = new FailoverQueue($manager, $events, ['redis', 'sync']);

        $manager->expects('connection')->times(10)->with('redis')->returns($redis);
        $redis->expects('totalSize')->with()->returns(9);
        $redis->expects('totalPendingSize')->with()->returns(2);
        $redis->expects('totalDelayedSize')->with()->returns(3);
        $redis->expects('totalReservedSize')->with()->returns(4);
        $redis->expects('pendingJobs')->with('emails')->returns($pending = new Collection(['pending']));
        $redis->expects('delayedJobs')->with('emails')->returns($delayed = new Collection(['delayed']));
        $redis->expects('reservedJobs')->with('emails')->returns($reserved = new Collection(['reserved']));
        $redis->expects('allPendingJobs')->returns($allPending = new Collection(['all-pending']));
        $redis->expects('allDelayedJobs')->returns($allDelayed = new Collection(['all-delayed']));
        $redis->expects('allReservedJobs')->returns($allReserved = new Collection(['all-reserved']));

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
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $redis = Double::for(RedisQueue::class);
        $queue = new FailoverQueue($manager, $events, ['redis', 'sync']);

        $manager->expects('connection')->with('redis')->returns($redis);
        $redis->expects('pop')->with('emails', 2)->returns(null);

        $this->assertNull($queue->pop('emails', 2));
    }

    public function testPopDoesNotForwardTheQueueIndexToAnOrdinaryConnection(): void
    {
        $manager = Double::for(QueueManager::class);
        $events = Double::for(DispatcherContract::class);
        $sync = Double::for(SyncQueue::class);
        $queue = new FailoverQueue($manager, $events, ['sync']);

        $manager->expects('connection')->with('sync')->returns($sync);
        $sync->expects('pop')->with('emails')->returns(null);

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
    public function size(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    public function pendingSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    public function delayedSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    public function reservedSize(UnitEnum|string|null $queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob(UnitEnum|string|null $queue = null): ?int
    {
        return null;
    }

    public function pushOn(UnitEnum|string|null $queue, object|string $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        return null;
    }

    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return $this->push($job, $data, $queue);
    }

    public function laterOn(UnitEnum|string|null $queue, DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    public function bulk(array $jobs, mixed $data = '', UnitEnum|string|null $queue = null): mixed
    {
        return null;
    }

    public function pop(UnitEnum|string|null $queue = null): ?Job
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

    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
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

    public function push(object|string $job, mixed $data = '', UnitEnum|string|null $queue = null): mixed
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
