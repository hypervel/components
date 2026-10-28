<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusContract;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher as EventsContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Bus\PendingDispatch;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\SqsQueue;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJob;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SqsBatchLifecycleTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testCustomBulkDispatchRetainsTheAcceptedUniqueLock(bool $afterCommit): void
    {
        [$queue, $client, $events, $container] = $this->queue();
        $cache = new Repository(new WorkerArrayStore);
        $container->instance(Cache::class, $cache);
        $container->instance(BusContract::class, new BusDispatcher($container, static fn () => $queue));
        $transactions = new DatabaseTransactionsManager;
        $container->instance('db.transactions', $transactions);
        $transactions->begin('testing', 1);
        $sent = false;
        $client->expects('sendMessageBatch')->andReturnUsing(static function () use (&$sent): Result {
            $sent = true;

            return new Result(['Successful' => [['Id' => '0', 'MessageId' => 'accepted']]]);
        });
        $job = new SqsUniqueBulkJob;
        $job->afterCommit = $afterCommit;

        $pending = new PendingDispatch($job);
        unset($pending);

        $this->assertSame(! $afterCommit, $sent);
        $this->assertTrue($cache->lock(UniqueLock::getKey($job))->isLocked());
        $transactions->commit('testing', 1, 0);
        $this->assertTrue($sent);
        $this->assertFalse(DispatchLockContext::has($job));
        $this->assertTrue($cache->lock(UniqueLock::getKey($job))->isLocked());
    }

    #[TestWith([JobPayloadFinalizing::class])]
    #[TestWith([JobQueueing::class])]
    public function testPreparationFailureTerminatesEveryOutstandingMember(string $eventClass): void
    {
        [$queue, $client, $events] = $this->queue();
        $exception = new RuntimeException('Second member failed.');
        $count = 0;
        $events->listen($eventClass, static function () use (&$count, $exception): void {
            if (++$count === 2) {
                throw $exception;
            }
        });
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $client->shouldNotReceive('sendMessageBatch');

        try {
            $queue->bulk(['first', 'second', 'third']);
            $this->fail('The second member should fail preparation.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(['first', 'second', 'third'], array_column($failed, 'job'));
        $this->assertSame([$exception, $exception, $exception], array_column($failed, 'exception'));
    }

    #[TestWith([JobPayloadFinalizing::class])]
    #[TestWith([JobQueueing::class])]
    public function testPreparationCancellationDoesNotNotifyOutstandingMembers(string $eventClass): void
    {
        [$queue, $client, $events] = $this->queue();
        $cleanup = new RuntimeException('Preparation cleanup failed.');
        $gate = $this->armCurrentCoroutineCancellation();
        $events->listen($eventClass, static function () use ($gate, $cleanup): never {
            try {
                $gate->push(true);
            } finally {
                throw $cleanup;
            }
        });
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $client->shouldNotReceive('sendMessageBatch');

        try {
            $queue->bulk(['first', 'second']);
            $this->fail('Expected preparation cancellation.');
        } catch (Throwable $caught) {
            $this->assertSame($cleanup, $caught);
            $this->assertInstanceOf(CanceledException::class, $caught->getPrevious());
            $this->assertSame([], $failed);
        } finally {
            $gate->close();
        }
    }

    public function testFailureNotificationsDrainAndRetainThePrimaryAttemptError(): void
    {
        [$queue, $client, $events] = $this->queue();
        $primary = new RuntimeException('Transport failed.');
        $listener = new RuntimeException('Listener failed.');
        $client->expects('sendMessageBatch')->andThrow($primary);
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $events->listen(JobQueueingFailed::class, static fn () => throw $listener);

        try {
            $queue->bulk(['first', 'second']);
            $this->fail('The failure listener should throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($listener, $caught);
        }

        $this->assertSame(['first', 'second'], array_column($failed, 'job'));
        $this->assertSame([$primary, $primary], array_column($failed, 'exception'));
    }

    #[TestWith([JobQueued::class])]
    #[TestWith([JobQueueingFailed::class])]
    public function testNotificationCancellationStopsAfterAnEarlierOrdinaryFailure(string $eventClass): void
    {
        [$queue, $client, $events] = $this->queue();
        if ($eventClass === JobQueued::class) {
            $client->expects('sendMessageBatch')->andReturn(new Result([
                'Successful' => [
                    ['Id' => '0', 'MessageId' => 'first'],
                    ['Id' => '1', 'MessageId' => 'second'],
                    ['Id' => '2', 'MessageId' => 'third'],
                ],
            ]));
        } else {
            $client->expects('sendMessageBatch')->andThrow(new RuntimeException('Transport failed.'));
        }
        $cleanup = new RuntimeException('Notification cleanup failed.');
        $gate = $this->armCurrentCoroutineCancellation();
        $notified = [];
        $events->listen($eventClass, static function (JobQueued|JobQueueingFailed $event) use ($gate, $cleanup, &$notified): void {
            $notified[] = $event->job;
            if ($event->job === 'first') {
                throw new RuntimeException('First listener failed.');
            }
            if ($event->job === 'second') {
                try {
                    $gate->push(true);
                } finally {
                    throw $cleanup;
                }
            }
        });

        try {
            $queue->bulk(['first', 'second', 'third']);
            $this->fail('Expected notification cancellation.');
        } catch (Throwable $caught) {
            $this->assertSame($cleanup, $caught);
            $this->assertInstanceOf(CanceledException::class, $caught->getPrevious());
            $this->assertSame(['first', 'second'], $notified);
        } finally {
            $gate->close();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSuccessListenerFailurePreservesAcceptedEntriesAndCleansExplicitRejects(bool $failureListenerCanceled): void
    {
        [$queue, $client, $events, $container] = $this->queue(['enabled' => true, 'always' => true, 'store' => 'overflow']);
        $store = new Repository(new WorkerArrayStore);
        $cache = m::mock(CacheFactory::class);
        $cache->expects('store')->with('overflow')->andReturn($store);
        $container->instance('cache', $cache);
        $paths = [];
        $client->expects('sendMessageBatch')->once()->andReturnUsing(static function (array $arguments) use (&$paths): Result {
            $paths = array_map(static fn (array $entry): string => json_decode($entry['MessageBody'], true)['@pointer'], $arguments['Entries']);

            return new Result([
                'Successful' => array_map(static fn (int $id): array => ['Id' => (string) $id, 'MessageId' => 'accepted-' . $id], range(0, 8)),
                'Failed' => [['Id' => '9', 'Code' => 'InvalidParameterValue', 'Message' => 'Rejected']],
            ]);
        });
        $queued = $failed = [];
        $listener = new RuntimeException('Monitoring failed.');
        $cancellation = new RuntimeException('Failure listener cleanup failed.', previous: new CanceledException);
        $events->listen(JobQueued::class, static function (JobQueued $event) use (&$queued): void {
            $queued[] = $event->job;
        });
        $events->listen(JobQueued::class, static fn () => throw $listener);
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed, $failureListenerCanceled, $cancellation): void {
            $failed[] = $event->job;
            if ($failureListenerCanceled) {
                throw $cancellation;
            }
        });

        try {
            $queue->bulk(array_map('strval', range(1, 11)));
            $this->fail('The monitoring failure should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failureListenerCanceled ? $cancellation : $listener, $caught);
        }

        $this->assertSame(array_map('strval', range(1, 9)), $queued);
        $this->assertSame($failureListenerCanceled ? ['10'] : ['10', '11'], $failed);
        $this->assertFalse($store->has($paths[9]));

        foreach (array_slice($paths, 0, 9) as $path) {
            $this->assertTrue($store->has($path));
        }
    }

    /**
     * Arm cancellation at a controlled channel handoff.
     */
    private function armCurrentCoroutineCancellation(): Channel
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            if ($gate->pop(5)) {
                EngineCoroutine::cancelById($coroutineId, throwException: true);
            }
        });

        return $gate;
    }

    /**
     * Create a queue with real lifecycle dispatch and a controlled SDK boundary.
     *
     * @return array{SqsQueue, SqsClient, Dispatcher, Container}
     */
    protected function queue(array $overflow = []): array
    {
        $container = new Container;
        Container::setInstance($container);
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventsContract::class, $events);
        $client = m::mock(SqsClient::class);
        $queue = new SqsQueue($client, 'jobs', overflowStorage: $overflow);
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        return [$queue, $client, $events, $container];
    }
}

class SqsUniqueBulkJob extends FakeSqsJob implements ShouldBeUnique
{
    /**
     * Route a normal pending dispatch through the supported bulk API.
     */
    public function queue(QueueContract $queue, object $command): mixed
    {
        return $queue->bulk([$command]);
    }
}
