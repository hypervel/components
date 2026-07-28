<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Sqs\SqsClient;
use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\Job as JobContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Jobs\BeanstalkdJob;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\Jobs\SqsJob;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Pheanstalk\Contract\JobIdInterface;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class QueuePoolProxyTest extends TestCase
{
    public function testEnumeratedSynchronousSurfaceUsesBorrowScopedInvocation(): void
    {
        $queue = m::mock(QueuePoolProxyTestQueue::class)->makePartial();
        [$proxy] = $this->proxy(fn () => $queue);
        $proxy->setConnectionName('logical');

        $queue->shouldReceive('size')->once()->with('queue')->andReturn(1);
        $queue->shouldReceive('pendingSize')->once()->with('queue')->andReturn(2);
        $queue->shouldReceive('delayedSize')->once()->with('queue')->andReturn(3);
        $queue->shouldReceive('reservedSize')->once()->with('queue')->andReturn(4);
        $queue->shouldReceive('pendingJobs')->once()->with('queue')->andReturn($pending = new Collection(['pending']));
        $queue->shouldReceive('delayedJobs')->once()->with('queue')->andReturn($delayed = new Collection(['delayed']));
        $queue->shouldReceive('reservedJobs')->once()->with('queue')->andReturn($reserved = new Collection(['reserved']));
        $queue->shouldReceive('allPendingJobs')->once()->andReturn($allPending = new Collection(['all-pending']));
        $queue->shouldReceive('allDelayedJobs')->once()->andReturn($allDelayed = new Collection(['all-delayed']));
        $queue->shouldReceive('allReservedJobs')->once()->andReturn($allReserved = new Collection(['all-reserved']));
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->once()->with('queue')->andReturn(5);
        $queue->shouldReceive('push')->once()->with('job', 'data', 'queue')->andReturn('pushed');
        $queue->shouldReceive('pushOn')->once()->with('queue', 'job', 'data')->andReturn('pushed-on');
        $queue->shouldReceive('pushRaw')->once()->with('payload', 'queue', ['option' => true])->andReturn('raw');
        $queue->shouldReceive('later')->once()->with(10, 'job', 'data', 'queue')->andReturn('later');
        $queue->shouldReceive('laterOn')->once()->with('queue', 10, 'job', 'data')->andReturn('later-on');
        $queue->shouldReceive('bulk')->once()->with(['job'], 'data', 'queue')->andReturn('bulk');

        $this->assertSame('logical', $proxy->getConnectionName());
        $this->assertSame(1, $proxy->size('queue'));
        $this->assertSame(2, $proxy->pendingSize('queue'));
        $this->assertSame(3, $proxy->delayedSize('queue'));
        $this->assertSame(4, $proxy->reservedSize('queue'));
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
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
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

    public function testPopPinsTheQueueUntilTheJobTerminates(): void
    {
        $job = new QueuePoolProxyTestJob(m::mock(ContainerContract::class));
        $queue = new QueuePoolProxyTestQueue($job);
        [$proxy, $pools] = $this->proxy(fn () => $queue);
        $proxy->setConnectionName('logical');

        $popped = $proxy->pop('jobs');
        $this->assertSame($job, $popped);
        $this->assertSame('logical', $queue->getConnectionName());

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(1, $pool->getBorrowedObjectNumber());
        $this->assertSame(0, $pool->getObjectNumberInPool());

        $popped->delete();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testNullPopReleasesImmediately(): void
    {
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue);

        $this->assertNull($proxy->pop());

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testPopFailureStaysPrimaryWhenReleaseCallbackAlsoFails(): void
    {
        $operationException = new Exception('pop failed');
        $finalizationException = new Exception('reset failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($finalizationException);
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
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    public function testNonLeaseAwareJobIsRequeuedBeforeFailingClosed(): void
    {
        $job = m::mock(JobContract::class);
        $job->shouldReceive('release')->once()->with(0);
        [$proxy, $pools] = $this->proxy(fn () => new QueuePoolProxyTestQueue($job));

        try {
            $proxy->pop();
            $this->fail('The lease-awareness exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('require jobs extending', $exception->getMessage());
        }

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testFailedRequeueDiscardsTheBackendAndAReplacementIsCreated(): void
    {
        $requeueException = new Exception('requeue failed');
        $job = m::mock(JobContract::class);
        $job->shouldReceive('release')->once()->with(0)->andThrow($requeueException);
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
        $this->assertSame(0, $pool->getCurrentObjectNumber());

        $this->assertSame(0, $proxy->size());
        $this->assertSame(2, $created);
        $this->assertSame(1, $pool->getCurrentObjectNumber());
    }

    public function testTerminalBackendFailureDiscardsTheQueueAndCreatesAReplacement(): void
    {
        $backendException = new Exception('delete failed');
        $client = m::mock(SqsClient::class);
        $client->shouldReceive('deleteMessage')->once()->andThrow($backendException);
        $job = new SqsJob(
            m::mock(ContainerContract::class),
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

        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(0, $pool->getCurrentObjectNumber());

        $this->assertSame(0, $proxy->size());
        $this->assertSame(2, $created);
        $this->assertSame(1, $pool->getCurrentObjectNumber());
    }

    public function testAbandonedJobReleasesThroughItsLeaseDestructor(): void
    {
        $job = new QueuePoolProxyTestJob(m::mock(ContainerContract::class));
        [$proxy, $pools] = $this->proxy(function () use (&$job) {
            $queue = new QueuePoolProxyTestQueue($job);
            $job = null;

            return $queue;
        });

        $popped = $proxy->pop();
        $pool = $pools->get($proxy->getPoolName());
        $this->assertSame(1, $pool->getBorrowedObjectNumber());

        unset($popped);
        gc_collect_cycles();

        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    #[DataProvider('beanstalkAttachmentFailureDataProvider')]
    public function testBeanstalkAttemptPrimingFailureRecoversTheReservedJob(
        bool $recoveryFails,
    ): void {
        $attachmentException = new Exception('stats failed');
        $recoveryException = new Exception('release failed');
        $pheanstalk = m::mock(implode(',', [
            PheanstalkManagerInterface::class,
            PheanstalkPublisherInterface::class,
            PheanstalkSubscriberInterface::class,
        ]));
        $pheanstalk->shouldReceive('statsJob')->once()->andThrow($attachmentException);

        if ($recoveryFails) {
            $pheanstalk->shouldReceive('release')->once()->andThrow($recoveryException);
        } else {
            $pheanstalk->shouldReceive('release')->once();
        }

        $job = new BeanstalkdJob(
            m::mock(ContainerContract::class),
            $pheanstalk,
            m::mock(JobIdInterface::class),
            'connection',
            'jobs',
        );
        $handler = m::mock(ExceptionHandler::class);

        if ($recoveryFails) {
            $handler->shouldReceive('report')->once()->with($recoveryException);
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
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame($recoveryFails ? 0 : 1, $pool->getCurrentObjectNumber());
        $this->assertSame($recoveryFails ? 0 : 1, $pool->getObjectNumberInPool());
    }

    public static function beanstalkAttachmentFailureDataProvider(): array
    {
        return [
            'successful recovery' => [false],
            'failed recovery' => [true],
        ];
    }

    /**
     * Create a queue proxy with an isolated pool registry.
     *
     * @return array{QueuePoolProxy, PoolManager}
     */
    protected function proxy(
        Closure $resolver,
        ?Closure $releaseCallback = null,
        ?ExceptionHandler $handler = null,
        string $resourceType = 'queue-test',
    ): array {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);

        if ($handler !== null) {
            $container->instance(ExceptionHandler::class, $handler);
        }

        Container::setInstance($container);
        $pools = new PoolManager;
        $definition = new PoolDefinition(
            'queue-test',
            $resourceType,
            'auto:queue-test',
            PoolOptions::fromArray(['max_objects' => 1]),
        );

        return [
            new QueuePoolProxy($definition, $resolver, $pools, $releaseCallback),
            $pools,
        ];
    }
}

class QueuePoolProxyTestQueue extends BaseQueue implements QueueContract
{
    public function __construct(
        public ?JobContract $job = null,
        public ?Throwable $popException = null,
    ) {
    }

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

    public function pendingJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    public function delayedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    public function reservedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    public function allPendingJobs(): Collection
    {
        return new Collection;
    }

    public function allDelayedJobs(): Collection
    {
        return new Collection;
    }

    public function allReservedJobs(): Collection
    {
        return new Collection;
    }

    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return null;
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    public function pushOn(?string $queue, object|string $job, mixed $data = ''): mixed
    {
        return null;
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    public function later(
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
        ?string $queue = null,
    ): mixed {
        return null;
    }

    public function laterOn(
        ?string $queue,
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data = '',
    ): mixed {
        return null;
    }

    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    public function pop(?string $queue = null): ?JobContract
    {
        if ($this->popException !== null) {
            throw $this->popException;
        }

        $job = $this->job;
        $this->job = null;

        return $job;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function setConnectionName(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    public function hasAfterCommitDispatcher(): bool
    {
        return $this->afterCommitDispatcher !== null;
    }
}

class QueuePoolProxyTestJob extends Job
{
    public function __construct(ContainerContract $container)
    {
        $this->container = $container;
        $this->connectionName = 'connection';
        $this->queue = 'queue';
    }

    public function getJobId(): string
    {
        return 'job';
    }

    public function getRawBody(): string
    {
        return '{"job":"job","data":[]}';
    }

    public function attempts(): int
    {
        return 1;
    }

    public function delete(): void
    {
        parent::delete();
        $this->releasePoolLease();
    }

    public function release(int $delay = 0): void
    {
        parent::release($delay);
        $this->releasePoolLease();
    }
}
