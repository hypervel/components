<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
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
use Hypervel\Contracts\Debug\ExceptionHandler;
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
use Hypervel\Queue\Events\JobQueueingCanceled;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\SqsQueue;
use Hypervel\Tests\Queue\Fixtures\FakeSqsJob;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SqsBatchLifecycleTest extends TestCase
{
    use VerifiesDoubles;

    protected MockHandler $transport;

    /**
     * Verify the queued SDK responses were consumed and clean up the test environment.
     */
    protected function tearDown(): void
    {
        try {
            $this->assertCount(0, $this->transport);

            if (($command = $this->transport->getLastCommand()) !== null) {
                $this->assertSame('SendMessageBatch', $command->getName());
            }
        } finally {
            parent::tearDown();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCustomBulkDispatchRetainsTheAcceptedUniqueLock(bool $afterCommit): void
    {
        [$queue, $transport, $events, $container] = $this->queue();
        $cache = new Repository(new WorkerArrayStore);
        $container->instance(Cache::class, $cache);
        $container->instance(BusContract::class, new BusDispatcher($container, static fn () => $queue));
        $transactions = new DatabaseTransactionsManager;
        $container->instance('db.transactions', $transactions);
        $transactions->begin('testing', 1);
        $sent = false;
        $transport->append(static function () use (&$sent): Result {
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
        [$queue, , $events] = $this->queue();
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
    public function testPreparationCancellationNotifiesOutstandingMembersOfTheCancellation(string $eventClass): void
    {
        [$queue, , $events] = $this->queue();
        $cleanup = new RuntimeException('Preparation cleanup failed.');
        $gate = $this->armCurrentCoroutineCancellation();
        $events->listen($eventClass, static function () use ($gate, $cleanup): never {
            try {
                $gate->push(true);
            } finally {
                throw $cleanup;
            }
        });
        $failed = $canceled = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $events->listen(JobQueueingCanceled::class, static function (JobQueueingCanceled $event) use (&$canceled): void {
            $canceled[] = $event;
        });

        try {
            $queue->bulk(['first', 'second']);
            $this->fail('Expected preparation cancellation.');
        } catch (Throwable $caught) {
            $this->assertSame($cleanup, $caught);
            $this->assertInstanceOf(CanceledException::class, $caught->getPrevious());
        } finally {
            $gate->close();
        }

        $this->assertSame([], $failed);
        $this->assertSame(['first', 'second'], array_column($canceled, 'job'));
        $this->assertSame([$cleanup, $cleanup], array_column($canceled, 'cancellation'));
        $this->assertSame([false, false], array_column($canceled, 'sendAttempted'));
    }

    public function testFailureNotificationsDrainAndRetainThePrimaryAttemptError(): void
    {
        [$queue, $transport, $events, $container] = $this->queue();
        $primary = new RuntimeException('Transport failed.');
        $listener = new RuntimeException('Listener failed.');
        $transport->append($primary);
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $events->listen(JobQueueingFailed::class, static fn () => throw $listener);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($listener));
        $container->instance(ExceptionHandler::class, $handler);

        try {
            $queue->bulk(['first', 'second']);
            $this->fail('The primary attempt error should be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($primary, $caught);
        }

        $this->assertSame(['first', 'second'], array_column($failed, 'job'));
        $this->assertSame([$primary, $primary], array_column($failed, 'exception'));
    }

    #[TestWith([JobQueued::class])]
    #[TestWith([JobQueueingFailed::class])]
    public function testNotificationCancellationStillNotifiesLaterMembersAndReportsTheEarlierFailure(string $eventClass): void
    {
        [$queue, $transport, $events, $container] = $this->queue();
        $transportFailure = new RuntimeException('Transport failed.');
        if ($eventClass === JobQueued::class) {
            $transport->append(new Result([
                'Successful' => [
                    ['Id' => '0', 'MessageId' => 'first'],
                    ['Id' => '1', 'MessageId' => 'second'],
                    ['Id' => '2', 'MessageId' => 'third'],
                ],
            ]));
        } else {
            $transport->append($transportFailure);
        }
        $cleanup = new RuntimeException('Notification cleanup failed.');
        $listenerFailure = new RuntimeException('First listener failed.');
        $gate = $this->armCurrentCoroutineCancellation();
        $notified = $canceled = [];
        $events->listen($eventClass, static function (JobQueued|JobQueueingFailed $event) use ($gate, $cleanup, $listenerFailure, &$notified): void {
            $notified[] = $event->job;
            if ($event->job === 'first') {
                throw $listenerFailure;
            }
            if ($event->job === 'second') {
                try {
                    $gate->push(true);
                } finally {
                    throw $cleanup;
                }
            }
        });

        $events->listen(JobQueueingCanceled::class, static function (JobQueueingCanceled $event) use (&$canceled): void {
            $canceled[] = $event;
        });
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($listenerFailure));
        $container->instance(ExceptionHandler::class, $handler);

        try {
            $queue->bulk(['first', 'second', 'third']);
            $this->fail('Expected notification cancellation.');
        } catch (Throwable $caught) {
            $this->assertSame($cleanup, $caught);
            $this->assertInstanceOf(CanceledException::class, $caught->getPrevious());
        } finally {
            $gate->close();
        }

        $this->assertSame(['first', 'second', 'third'], $notified);
        $this->assertSame(['second'], array_column($canceled, 'job'));
        $this->assertSame($cleanup, $canceled[0]->cancellation);
        $this->assertSame($eventClass === JobQueued::class, $canceled[0]->queued);
        $this->assertSame($eventClass === JobQueued::class ? null : $transportFailure, $canceled[0]->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSuccessListenerFailurePreservesAcceptedEntriesAndCleansExplicitRejects(bool $failureListenerCanceled): void
    {
        [$queue, $transport, $events, $container] = $this->queue(['enabled' => true, 'always' => true, 'store' => 'overflow']);
        $store = new Repository(new WorkerArrayStore);
        $cache = Double::for(CacheFactory::class)->strict();
        $cache->expects('store')->with('overflow')->returns($store);
        $container->instance('cache', $cache);
        $paths = [];
        $transport->append(static function (CommandInterface $command) use (&$paths): Result {
            $arguments = $command->toArray();
            $paths = array_map(static fn (array $entry): string => json_decode($entry['MessageBody'], true)['@pointer'], $arguments['Entries']);

            return new Result([
                'Successful' => array_map(static fn (int $id): array => ['Id' => (string) $id, 'MessageId' => 'accepted-' . $id], range(0, 8)),
                'Failed' => [['Id' => '9', 'Code' => 'InvalidParameterValue', 'Message' => 'Rejected', 'SenderFault' => true]],
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
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($listener));
        if ($failureListenerCanceled) {
            $handler->expects('report')->with(Argument::type(SqsException::class));
        }
        $container->instance(ExceptionHandler::class, $handler);

        try {
            $queue->bulk(array_map('strval', range(1, 11)));
            $this->fail('The rejection should be rethrown.');
        } catch (Throwable $caught) {
            if ($failureListenerCanceled) {
                $this->assertSame($cancellation, $caught);
            } else {
                $this->assertInstanceOf(SqsException::class, $caught);
                $this->assertSame('InvalidParameterValue', $caught->getAwsErrorCode());
            }
        }

        $this->assertSame(array_map('strval', range(1, 9)), $queued);
        $this->assertSame(['10', '11'], $failed);
        $this->assertFalse($store->has($paths[9]));

        foreach (array_slice($paths, 0, 9) as $path) {
            $this->assertTrue($store->has($path));
        }
    }

    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function testCancellationDuringReportingStillNotifiesEveryMemberAndReportsTheRejection(bool $cleanupThrows, bool $overflowCleanup): void
    {
        [$queue, $transport, $events, $container] = $this->queue(['enabled' => $overflowCleanup, 'always' => true, 'store' => 'overflow']);
        $transport->append(new Result([
            'Successful' => [['Id' => '0', 'MessageId' => 'accepted']],
            'Failed' => [
                ['Id' => '1', 'Code' => 'InvalidParameterValue', 'Message' => 'Rejected', 'SenderFault' => true],
                ['Id' => '2', 'Code' => 'InvalidParameterValue', 'Message' => 'Rejected', 'SenderFault' => true],
            ],
        ]));
        $reportedFailure = new RuntimeException($overflowCleanup ? 'Overflow deletion failed.' : 'Queued listener failed.');
        $deleted = [];
        if ($overflowCleanup) {
            $store = Double::for(Cache::class)->strict();
            $store->expects('put')->times(3)->returns(true);
            $store->expects('forget')->times(2)->resolves(static function (string $path) use (&$deleted, $reportedFailure): bool {
                $deleted[] = $path;
                if (count($deleted) === 1) {
                    throw $reportedFailure;
                }

                return true;
            });
            $cache = Double::for(CacheFactory::class)->strict();
            $cache->expects('store')->with('overflow')->returns($store);
            $container->instance('cache', $cache);
        }
        $queued = [];
        $events->listen(JobQueued::class, static function (JobQueued $event) use (&$queued, $overflowCleanup, $reportedFailure): void {
            $queued[] = $event->job;
            if (! $overflowCleanup) {
                throw $reportedFailure;
            }
        });
        $failed = [];
        $events->listen(JobQueueingFailed::class, static function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $gate = $this->armCurrentCoroutineCancellation();
        $cleanup = new RuntimeException('Reporting cleanup failed.');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($reportedFailure))->resolves(static function () use ($gate, $cleanup, $cleanupThrows): void {
            try {
                $gate->push(true);
            } finally {
                if ($cleanupThrows) {
                    throw $cleanup;
                }
            }
        });
        $handler->expects('report')->with(Argument::type(SqsException::class));
        $container->instance(ExceptionHandler::class, $handler);

        try {
            $queue->bulk(['first', 'second', 'third']);
            $this->fail('Expected reporting cancellation to propagate.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanup, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }
            $this->assertSame(['second', 'third'], array_column($failed, 'job'));
            $this->assertContainsOnlyInstancesOf(SqsException::class, array_column($failed, 'exception'));
            $this->assertSame(['first'], $queued);
            $this->assertCount($overflowCleanup ? 2 : 0, $deleted);
        } finally {
            $gate->close();
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
     * @return array{SqsQueue, MockHandler, Dispatcher, Container}
     */
    protected function queue(array $overflow = []): array
    {
        $container = new Container;
        Container::setInstance($container);
        $events = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance(EventsContract::class, $events);
        $this->transport = $transport = new MockHandler;
        $client = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $transport,
        ]);
        $queue = new SqsQueue($client, 'jobs', overflowStorage: $overflow);
        $queue->setContainer($container);
        $queue->setConnectionName('sqs');

        return [$queue, $transport, $events, $container];
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
