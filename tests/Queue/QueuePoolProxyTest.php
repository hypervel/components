<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\MockHandler;
use Aws\Sqs\SqsClient;
use Closure;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\IndexAwareQueue;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\ClearableQueuePoolProxy;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Support\Collection;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnitEnum;

class QueuePoolProxyTest extends TestCase
{
    use VerifiesDoubles;

    /** @var list<PoolManager> */
    private array $poolManagers = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->poolManagers as $poolManager) {
            $poolManager->purgeAll();
        }
    }

    public function testEnumeratedSynchronousSurfaceUsesBorrowScopedInvocation(): void
    {
        $queue = Double::for(new QueuePoolProxyTestQueue)->passthru();
        [$proxy] = $this->proxy(fn () => $queue);
        $proxy->setConnectionName('logical');

        $queue->expects('size')->with('queue')->returns(1);
        $queue->expects('pendingSize')->with('queue')->returns(2);
        $queue->expects('delayedSize')->with('queue')->returns(3);
        $queue->expects('reservedSize')->with('queue')->returns(4);
        $queue->expects('totalSize')->with()->returns(18);
        $queue->expects('totalPendingSize')->with()->returns(5);
        $queue->expects('totalDelayedSize')->with()->returns(6);
        $queue->expects('totalReservedSize')->with()->returns(7);
        $queue->expects('pendingJobs')->with('queue')->returns($pending = new Collection(['pending']));
        $queue->expects('delayedJobs')->with('queue')->returns($delayed = new Collection(['delayed']));
        $queue->expects('reservedJobs')->with('queue')->returns($reserved = new Collection(['reserved']));
        $queue->expects('allPendingJobs')->returns($allPending = new Collection(['all-pending']));
        $queue->expects('allDelayedJobs')->returns($allDelayed = new Collection(['all-delayed']));
        $queue->expects('allReservedJobs')->returns($allReserved = new Collection(['all-reserved']));
        $queue->expects('creationTimeOfOldestPendingJob')->with('queue')->returns(5);
        $queue->expects('push')->with('job', 'data', 'queue')->returns('pushed');
        $queue->expects('pushOn')->with('queue', 'job', 'data')->returns('pushed-on');
        $queue->expects('pushRaw')->with('payload', 'queue', ['option' => true])->returns('raw');
        $queue->expects('later')->with(10, 'job', 'data', 'queue')->returns('later');
        $queue->expects('laterOn')->with('queue', 10, 'job', 'data')->returns('later-on');
        $queue->expects('bulk')->with(['job'], 'data', 'queue')->returns('bulk');

        $this->assertSame('logical', $proxy->getConnectionName());
        $this->assertSame(1, $proxy->size('queue'));
        $this->assertSame(2, $proxy->pendingSize('queue'));
        $this->assertSame(3, $proxy->delayedSize('queue'));
        $this->assertSame(4, $proxy->reservedSize('queue'));
        $this->assertSame(18, $proxy->totalSize());
        $this->assertSame(5, $proxy->totalPendingSize());
        $this->assertSame(6, $proxy->totalDelayedSize());
        $this->assertSame(7, $proxy->totalReservedSize());
        $this->assertSame($pending, $proxy->pendingJobs('queue'));
        $this->assertSame($delayed, $proxy->delayedJobs('queue'));
        $this->assertSame($reserved, $proxy->reservedJobs('queue'));
        $this->assertSame($allPending, $proxy->allPendingJobs());
        $this->assertSame($allDelayed, $proxy->allDelayedJobs());
        $this->assertSame($allReserved, $proxy->allReservedJobs());
        $this->assertSame(5, $proxy->creationTimeOfOldestPendingJob('queue'));
        $this->assertSame('pushed', $proxy->push('job', 'data', 'queue'));
        $this->assertSame('pushed-on', $proxy->pushOn('queue', 'job', 'data'));
        $this->assertSame('raw', $proxy->pushRaw('payload', 'queue', ['option' => true]));
        $this->assertSame('later', $proxy->later(10, 'job', 'data', 'queue'));
        $this->assertSame('later-on', $proxy->laterOn('queue', 10, 'job', 'data'));
        $this->assertSame('bulk', $proxy->bulk(['job'], 'data', 'queue'));
    }

    public function testSqsConnectionAccessUsesOneBorrowAndClearsTheDispatcherBeforeDriverCleanup(): void
    {
        $queue = new QueuePoolProxyTestQueue;
        $cleanupObservedClearedDispatcher = false;
        [$proxy, $pools] = $this->proxy(
            fn () => $queue,
            function (QueuePoolProxyTestQueue $released) use (&$cleanupObservedClearedDispatcher): void {
                $cleanupObservedClearedDispatcher = ! $released->hasAfterCommitDispatcher();
            },
            resourceType: 'sqs',
        );

        $this->assertSame('result', $proxy->withConnection(function (BaseQueue $borrowed) use ($queue) {
            $this->assertSame($queue, $borrowed);
            $this->assertTrue($queue->hasAfterCommitDispatcher());

            return 'result';
        }));

        $pool = $pools->get($proxy->getPoolName());
        $this->assertTrue($cleanupObservedClearedDispatcher);
        $this->assertFalse($queue->hasAfterCommitDispatcher());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testDirectConnectionAccessRejectsNonSqsPoolsBeforeBorrowing(): void
    {
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue);

        try {
            $proxy->withConnection(fn () => null);
            $this->fail('The unsupported connection exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Direct queue connection access is only supported for SQS queues.', $exception->getMessage());
        }

        $this->assertFalse($pools->has($proxy->getPoolName()));
    }

    public function testPopDoesNotForwardTheQueueIndexToAnOrdinaryConnectionAndPinsItUntilTheJobTerminates(): void
    {
        $job = new QueuePoolProxyTestJob(Double::for(ContainerContract::class, override: true)->strict()->instance());
        $queue = new QueuePoolProxyTestQueue($job);
        [$proxy, $pools] = $this->proxy(fn () => $queue);
        $proxy->setConnectionName('logical');

        $popped = $proxy->pop('jobs', 2);
        $this->assertSame($job, $popped);
        $this->assertSame('logical', $queue->getConnectionName());
        $this->assertSame(['jobs'], $queue->lastPopArguments);

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(1, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getIdleCount());

        $popped->delete();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testPopForwardsTheQueueIndexToAnAwareConnection(): void
    {
        $queue = new QueuePoolProxyTestIndexAwareQueue;
        [$proxy, $pools] = $this->proxy(fn () => $queue);

        $this->assertNull($proxy->pop(IntegerQueueName::Zero, 2));
        $this->assertSame([IntegerQueueName::Zero, 2], $queue->lastIndexedPop);

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testClearUsesOneBorrowAndReleasesItImmediately(): void
    {
        $queue = new QueuePoolProxyTestClearableQueue;
        [$proxy, $pools] = $this->proxy(
            fn () => $queue,
            proxyClass: ClearableQueuePoolProxy::class,
        );
        /** @var ClearableQueuePoolProxy $proxy */
        $this->assertSame(3, $proxy->clear(IntegerQueueName::Zero));
        $this->assertSame(IntegerQueueName::Zero, $queue->lastClearedQueue);

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testClearWithoutQueueClearsTheDefaultQueue(): void
    {
        $queue = new QueuePoolProxyTestClearableQueue;
        $queue->lastClearedQueue = 'previous';
        [$proxy] = $this->proxy(
            fn (): QueuePoolProxyTestClearableQueue => $queue,
            proxyClass: ClearableQueuePoolProxy::class,
        );

        /** @var ClearableQueuePoolProxy $proxy */
        $this->assertSame(3, $proxy->clear());
        $this->assertNull($queue->lastClearedQueue);
    }

    public function testNullPopReleasesImmediately(): void
    {
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue);

        $this->assertNull($proxy->pop());

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testPopFailureStaysPrimaryWhenReleaseCallbackAlsoFails(): void
    {
        $operationException = new Exception('pop failed');
        $finalizationException = new Exception('reset failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($finalizationException);
        [$proxy, $pools] = $this->proxy(
            fn () => new QueuePoolProxyTestQueue(popException: $operationException),
            function () use ($finalizationException): never {
                throw $finalizationException;
            },
            $handler,
        );

        try {
            $proxy->pop();
            $this->fail('The pop exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($operationException, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testReleaseCancellationSupersedesAPopFailure(): void
    {
        $operationException = new Exception('pop failed');
        $releaseCancellation = new CanceledException('release canceled');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        [$proxy, $pools] = $this->proxy(
            fn () => new QueuePoolProxyTestQueue(popException: $operationException),
            static function () use ($releaseCancellation): never {
                throw $releaseCancellation;
            },
            $handler,
        );

        try {
            $proxy->pop();
            $this->fail('Expected release cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($releaseCancellation, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testNonLeaseAwareJobIsRequeuedBeforeFailingClosed(): void
    {
        $job = Double::for(JobContract::class)->strict();
        $job->expects('release')->with(0);
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue($job));

        try {
            $proxy->pop();
            $this->fail('The lease-awareness exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('require jobs extending', $exception->getMessage());
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testFailedRequeueDiscardsTheBackendAndAReplacementIsCreated(): void
    {
        $requeueException = new Exception('requeue failed');
        $job = Double::for(JobContract::class)->strict();
        $job->expects('release')->with(0)->throws($requeueException);
        $created = 0;
        [$proxy, $pools] = $this->proxy(function () use (&$created, $job) {
            ++$created;

            return new QueuePoolProxyTestQueue($created === 1 ? $job : null);
        });

        try {
            $proxy->pop();
            $this->fail('The failed-requeue exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($requeueException, $exception->getPrevious());
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getManagedCount());

        $this->assertSame(0, $proxy->size());
        $this->assertSame(2, $created);
        $this->assertSame(1, $pool->getManagedCount());
    }

    public function testNonLeaseAwareJobRequeueCancellationIsNotWrapped(): void
    {
        $cancellation = new CanceledException('requeue canceled');
        $job = Double::for(JobContract::class)->strict();
        $job->expects('release')->with(0)->throws($cancellation);
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue($job));

        try {
            $proxy->pop();
            $this->fail('Expected requeue cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    public function testTerminalBackendFailureDiscardsTheQueueAndCreatesAReplacement(): void
    {
        $backendException = new Exception('delete failed');
        $handler = new MockHandler;
        $handler->append($backendException);
        $client = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $handler,
        ]);
        $job = new SqsJob(
            Double::for(ContainerContract::class, override: true)->strict()->instance(),
            $client,
            [
                'MessageId' => 'message',
                'ReceiptHandle' => 'receipt',
                'Body' => '{}',
                'Attributes' => ['ApproximateReceiveCount' => 1],
            ],
            'connection',
            'queue-url',
        );
        $created = 0;
        [$proxy, $pools] = $this->proxy(function () use (&$created, $job) {
            ++$created;

            return new QueuePoolProxyTestQueue($created === 1 ? $job : null);
        });

        $popped = $proxy->pop();
        $this->assertSame($job, $popped);

        try {
            $popped->delete();
            $this->fail('The backend exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($backendException, $exception);
        }

        $this->assertSame('DeleteMessage', $handler->getLastCommand()->getName());
        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getManagedCount());

        $this->assertSame(0, $proxy->size());
        $this->assertSame(2, $created);
        $this->assertSame(1, $pool->getManagedCount());
    }

    public function testAbandonedJobReleasesThroughItsLeaseDestructor(): void
    {
        $job = new QueuePoolProxyTestJob(Double::for(ContainerContract::class, override: true)->strict()->instance());
        [$proxy, $pools] = $this->proxy(function () use (&$job) {
            $queue = new QueuePoolProxyTestQueue($job);
            $job = null;

            return $queue;
        });

        $popped = $proxy->pop();
        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(1, $pool->getBorrowedCount());

        unset($popped);
        gc_collect_cycles();

        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $pool->getIdleCount());
    }

    #[DataProvider('beanstalkAttachmentFailureDataProvider')]
    public function testBeanstalkAttemptPrimingFailureRecoversTheReservedJob(
        bool $recoveryFails,
    ): void {
        $attachmentException = new Exception('stats failed');
        $recoveryException = new Exception('release failed');
        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->throws($attachmentException);

        if ($recoveryFails) {
            $pheanstalk->expects('release')->throws($recoveryException);
        } else {
            $pheanstalk->expects('release');
        }

        $job = new BeanstalkdJob(
            Double::for(ContainerContract::class, override: true)->strict()->instance(),
            $pheanstalk,
            Double::for(JobIdInterface::class)->strict(),
            'connection',
            'jobs',
        );
        $handler = Double::for(ExceptionHandler::class)->strict();

        if ($recoveryFails) {
            $handler->expects('report')->with($recoveryException);
        }

        [$proxy, $pools] = $this->proxy(
            fn () => new QueuePoolProxyTestQueue($job),
            handler: $handler,
        );

        try {
            $proxy->pop();
            $this->fail('The attachment exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($attachmentException, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame($recoveryFails ? 0 : 1, $pool->getManagedCount());
        $this->assertSame($recoveryFails ? 0 : 1, $pool->getIdleCount());
    }

    public static function beanstalkAttachmentFailureDataProvider(): array
    {
        return [
            'successful recovery' => [false],
            'failed recovery' => [true],
        ];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAttachmentRecoveryCancellationSupersedesTheAttachmentFailure(bool $cleanupThrows): void
    {
        $attachmentException = new Exception('stats failed');
        $recoveryCancellation = new CanceledException('release canceled');
        if ($cleanupThrows) {
            $recoveryCancellation = new RuntimeException('Recovery cleanup failed.', previous: $recoveryCancellation);
        }
        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->throws($attachmentException);
        $pheanstalk->expects('release')->throws($recoveryCancellation);
        $job = new BeanstalkdJob(
            Double::for(ContainerContract::class, override: true)->strict()->instance(),
            $pheanstalk,
            Double::for(JobIdInterface::class)->strict(),
            'connection',
            'jobs',
        );
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        [$proxy, $pools] = $this->proxy(
            fn () => new QueuePoolProxyTestQueue($job),
            handler: $handler,
        );

        try {
            $proxy->pop();
            $this->fail('Expected recovery cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($recoveryCancellation, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAttachmentCancellationDoesNotStartBackendRecovery(bool $cleanupThrows): void
    {
        $attachmentCancellation = new CanceledException('stats canceled');
        if ($cleanupThrows) {
            $attachmentCancellation = new RuntimeException('Attachment cleanup failed.', previous: $attachmentCancellation);
        }
        $pheanstalk = Double::for(
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        )->strict();
        $pheanstalk->expects('statsJob')->throws($attachmentCancellation);
        $pheanstalk->expects('release')->never();
        $job = new BeanstalkdJob(
            Double::for(ContainerContract::class, override: true)->strict()->instance(),
            $pheanstalk,
            Double::for(JobIdInterface::class)->strict(),
            'connection',
            'jobs',
        );
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue($job));

        try {
            $proxy->pop();
            $this->fail('Expected attachment cancellation to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($attachmentCancellation, $exception);
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(0, $pool->getManagedCount());
    }

    /**
     * Create a queue proxy with an isolated pool registry.
     *
     * @param class-string<QueuePoolProxy> $proxyClass
     * @return array{QueuePoolProxy, PoolManager}
     */
    protected function proxy(
        Closure $createCallback,
        ?Closure $releaseCallback = null,
        ?ExceptionHandler $handler = null,
        string $resourceType = 'queue-test',
        string $proxyClass = QueuePoolProxy::class,
    ): array {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);

        if ($handler !== null) {
            $container->instance(ExceptionHandler::class, $handler);
        }

        Container::setInstance($container);
        $this->poolManagers[] = $pools = new PoolManager;
        $definition = new PoolDefinition(
            'queue-test',
            $resourceType,
            'auto:queue-test',
            PoolOptions::fromArray(['max_objects' => 1]),
        );

        return [
            new $proxyClass($definition, $createCallback, $pools, $releaseCallback),
            $pools,
        ];
    }
}

class QueuePoolProxyTestQueue extends NullQueue
{
    /** @var list<null|int|string|UnitEnum> */
    public array $lastPopArguments = [];

    /**
     * Create a new test queue instance.
     */
    public function __construct(
        public ?JobContract $job = null,
        public ?Throwable $popException = null,
    ) {
    }

    /**
     * Pop the configured job and record the arguments.
     */
    public function pop(UnitEnum|string|null $queue = null): ?JobContract
    {
        $this->lastPopArguments = func_get_args();

        if ($this->popException !== null) {
            throw $this->popException;
        }

        $job = $this->job;
        $this->job = null;

        return $job;
    }

    /**
     * Determine whether an after-commit dispatcher is assigned.
     */
    public function hasAfterCommitDispatcher(): bool
    {
        return $this->afterCommitDispatcher !== null;
    }
}

class QueuePoolProxyTestIndexAwareQueue extends QueuePoolProxyTestQueue implements IndexAwareQueue
{
    /** @var null|array{null|string|UnitEnum, int} */
    public ?array $lastIndexedPop = null;

    /**
     * Record the queue index and pop the configured job.
     */
    public function pop(UnitEnum|string|null $queue = null, int $index = 0): ?JobContract
    {
        $this->lastIndexedPop = [$queue, $index];

        return parent::pop($queue);
    }
}

class QueuePoolProxyTestClearableQueue extends QueuePoolProxyTestQueue implements ClearableQueue
{
    public UnitEnum|string|null $lastClearedQueue = null;

    /**
     * Record the cleared queue and return a fixed job count.
     */
    public function clear(UnitEnum|string|null $queue = null): int
    {
        $this->lastClearedQueue = $queue;

        return 3;
    }
}

class QueuePoolProxyTestJob extends Job
{
    /**
     * Create a new test job instance.
     */
    public function __construct(ContainerContract $container)
    {
        $this->container = $container;
        $this->connectionName = 'connection';
        $this->queue = 'queue';
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return 'job';
    }

    /**
     * Get the raw job payload.
     */
    public function getRawBody(): string
    {
        return '{"job":"job","data":[]}';
    }

    /**
     * Get the number of job attempts.
     */
    public function attempts(): int
    {
        return 1;
    }

    /**
     * Delete the job and release its pool lease.
     */
    public function delete(): void
    {
        parent::delete();
        $this->releasePoolLease();
    }

    /**
     * Release the job and its pool lease.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);
        $this->releasePoolLease();
    }
}
