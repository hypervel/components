<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\SyncQueue;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class QueueJobTest extends TestCase
{
    use VerifiesDoubles;

    public function testValidPayloadIsDecodedOnceAcrossAccessors(): void
    {
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => 'Handler@handle',
            'data' => ['value' => true],
            'maxTries' => 3,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('job-uuid', $job->uuid());
        $this->assertSame('Handler@handle', $job->getName());
        $this->assertSame(3, $job->maxTries());
        $this->assertSame(['value' => true], $job->payload()['data']);
        $this->assertSame(1, $job->rawBodyReads);
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejectedWithItsExactRawValue(string $payload, string $message): void
    {
        $job = new QueueJobPayloadStub($payload);

        try {
            $job->payload();
            $this->fail('Expected the payload to be rejected.');
        } catch (InvalidPayloadException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($payload, $e->value);
        }
    }

    public static function invalidPayloads(): array
    {
        return [
            'malformed JSON' => ['{invalid', 'Unable to decode the queue job payload'],
            'scalar JSON' => ['true', 'does not contain a valid job and data'],
            'missing job' => ['{"data":[]}', 'does not contain a valid job and data'],
            'empty job' => ['{"job":"","data":[]}', 'does not contain a valid job and data'],
            'non-string job' => ['{"job":1,"data":[]}', 'does not contain a valid job and data'],
            'missing data' => ['{"job":"Handler@handle"}', 'does not contain a valid job and data'],
        ];
    }

    public function testInvalidPayloadExceptionIsCachedByIdentity(): void
    {
        $job = new QueueJobPayloadStub('{invalid');

        $first = $this->capturePayloadException($job);
        $second = $this->capturePayloadException($job);

        $this->assertSame($first, $second);
        $this->assertSame(1, $job->rawBodyReads);
    }

    public function testNamedRawBodyExceptionIsCachedUnchanged(): void
    {
        $expected = new InvalidPayloadException('Driver payload failure.', 'raw');
        $job = new QueueJobPayloadStub('', $expected);

        $this->assertSame($expected, $this->capturePayloadException($job));
        $this->assertSame($expected, $this->capturePayloadException($job));
        $this->assertSame(1, $job->rawBodyReads);
    }

    public function testInvalidPayloadCanStillBeFailedAndDeleted(): void
    {
        $exception = new RuntimeException('Queue payload failed.');
        $job = new QueueJobPayloadStub('{invalid');
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(fn (JobFailed $event) => $event->job === $job && $event->exception === $exception));

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(Dispatcher::class)->returns($events);

        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        $job->fail($exception);

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
    }

    public function testFailedEventIsNotDispatchedWithoutListeners(): void
    {
        $exception = new RuntimeException('Queue payload failed.');
        $job = new QueueJobPayloadStub('{invalid');
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobFailed::class)->returns(false);
        $events->expects('dispatch')->never();

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(Dispatcher::class)->returns($events);

        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        $job->fail($exception);

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
    }

    public function testInvalidPayloadExceptionFromUserFailedHookStillPropagates(): void
    {
        $failure = new RuntimeException('Queue job failed.');
        $hookException = new InvalidPayloadException('User failed hook failed.');
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => QueueJobFailedHookStub::class,
            'data' => ['value' => true],
        ], JSON_THROW_ON_ERROR));
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(fn (JobFailed $event) => $event->job === $job && $event->exception === $failure));

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')
            ->with(QueueJobFailedHookStub::class)
            ->returns(new QueueJobFailedHookStub($hookException));
        $container->expects('make')->with(Dispatcher::class)->returns($events);

        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        try {
            $job->fail($failure);
            $this->fail('Expected the failed hook exception to be thrown.');
        } catch (InvalidPayloadException $e) {
            $this->assertSame($hookException, $e);
        }

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testBatchRollbackCancellationEscapesBeforeFurtherFailureCleanup(bool $cleanupThrows): void
    {
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => QueueJobTimeoutBatchStub::class,
            'data' => [
                'commandName' => QueueJobTimeoutBatchStub::class,
                'command' => serialize(new QueueJobTimeoutBatchStub),
            ],
        ], JSON_THROW_ON_ERROR));
        $timeout = TimeoutExceededException::forJob($job);
        $gate = $this->armCurrentCoroutineCancellation();
        $cleanupException = new RuntimeException('Rollback cleanup failed.');
        $batchRepository = Double::for(BatchRepository::class)->strict();
        $batchRepository->expects('rollBack')
            ->resolves(static function () use ($gate, $cleanupThrows, $cleanupException): never {
                try {
                    $gate->push(true);

                    throw new RuntimeException('Cancellation was not delivered.');
                } finally {
                    if ($cleanupThrows) {
                        Coroutine::sleep(0.001);
                        throw $cleanupException;
                    }
                }
            });
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(BatchRepository::class)->returns($batchRepository);
        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        try {
            $job->fail($timeout);
            $this->fail('Expected batch rollback cancellation to escape.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }
            $this->assertTrue($job->hasFailed());
            $this->assertFalse($job->isDeleted());
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFailedHookCancellationSkipsTheFailedEvent(bool $cleanupThrows): void
    {
        $failure = new RuntimeException('Queue job failed.');
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => QueueJobCancelingFailedHookStub::class,
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $cleanupException = $cleanupThrows ? new RuntimeException('Failed-hook cleanup failed.') : null;
        $handler = new QueueJobCancelingFailedHookStub($this->armCurrentCoroutineCancellation(), $cleanupException);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')
            ->with(QueueJobCancelingFailedHookStub::class)
            ->returns($handler);
        $container->expects('make')->never()->with(Dispatcher::class);
        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        try {
            $job->fail($failure);
            $this->fail('Expected failed hook cancellation to escape.');
        } catch (Throwable $exception) {
            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $exception);
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception);
            }
            $this->assertTrue($job->hasFailed());
            $this->assertTrue($job->isDeleted());
        }
    }

    #[DataProvider('failedJobDatabases')]
    public function testBatchRollbackFailurePreservesTimeoutForFailedJobCleanup(?string $databaseName): void
    {
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => QueueJobTimeoutBatchStub::class,
            'data' => [
                'commandName' => QueueJobTimeoutBatchStub::class,
                'command' => serialize(new QueueJobTimeoutBatchStub),
            ],
        ], JSON_THROW_ON_ERROR));
        $timeout = TimeoutExceededException::forJob($job);

        $batchRepository = Double::for(BatchRepository::class)->strict();
        $batchRepository->expects('rollBack')
            ->throws(new RuntimeException('Batch rollback failed.'));

        $config = new ConfigRepository([
            'queue' => [
                'failed' => [
                    'database' => $databaseName,
                    'driver' => 'database',
                ],
            ],
        ]);
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('rollBack')->with(0);
        $database = Double::for(ConnectionResolverInterface::class)->strict();
        $database->expects('connection')->with($databaseName)->returns($connection);

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(fn (JobFailed $event) => $event->job === $job && $event->exception === $timeout));

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(BatchRepository::class)->returns($batchRepository);
        $container->allows('make')->with('config')->returns($config);
        $container->expects('bound')->with('db')->returns(true);
        $container->expects('make')->with('db')->returns($database);
        $container->expects('make')
            ->with(QueueJobTimeoutBatchStub::class)
            ->returns(new QueueJobTimeoutBatchStub);
        $container->expects('make')->with(Dispatcher::class)->returns($events);

        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        $job->fail($timeout);
    }

    public static function failedJobDatabases(): array
    {
        return [
            'named connection' => ['sqlite'],
            'default connection' => [null],
        ];
    }

    public function testStringJobTimeoutDoesNotTreatCallerDataAsBatchMetadata(): void
    {
        $queue = new class extends SyncQueue {
            /**
             * Build a string-job payload through the real producer.
             */
            public function payloadFor(string $job, array $data): string
            {
                return $this->createPayload($job, 'default', $data);
            }
        };
        $job = new QueueJobPayloadStub($queue->payloadFor(
            QueueJobTimeoutBatchStub::class,
            ['command' => 'backup:run', 'commandName' => 'backup:run'],
        ));
        $timeout = TimeoutExceededException::forJob($job);
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(JobFailed::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(fn (JobFailed $event): bool => $event->job === $job && $event->exception === $timeout));

        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never()->with(BatchRepository::class);
        $container->allows('make')->with('config')->returns(new ConfigRepository(['queue' => ['failed' => ['driver' => null]]]));
        $container->expects('make')->with(QueueJobTimeoutBatchStub::class)->returns(new QueueJobTimeoutBatchStub);
        $container->expects('make')->with(Dispatcher::class)->returns($events);
        $job->setContainer($container->instance());
        $job->setConnectionName('redis');

        $job->fail($timeout);

        $this->assertTrue($job->hasFailed());
        $this->assertTrue($job->isDeleted());
    }

    /**
     * Arm exact cancellation of the current coroutine at a controlled channel handoff.
     */
    private function armCurrentCoroutineCancellation(): Channel
    {
        $gate = new Channel(1);
        $coroutineId = EngineCoroutine::id();

        EngineCoroutine::create(static function () use ($coroutineId, $gate): void {
            $gate->pop();
            EngineCoroutine::cancelById($coroutineId, throwException: true);
        });

        return $gate;
    }

    protected function capturePayloadException(Job $job): InvalidPayloadException
    {
        try {
            $job->payload();
        } catch (InvalidPayloadException $e) {
            return $e;
        }

        $this->fail('Expected the payload to be rejected.');
    }
}

class QueueJobPayloadStub extends Job
{
    public int $rawBodyReads = 0;

    public function __construct(
        protected string $rawBody,
        protected ?InvalidPayloadException $rawBodyException = null,
    ) {
    }

    public function getJobId(): int|string|null
    {
        return 'job-id';
    }

    public function getRawBody(): string
    {
        ++$this->rawBodyReads;

        if ($this->rawBodyException !== null) {
            throw $this->rawBodyException;
        }

        return $this->rawBody;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    public function setConnectionName(string $connectionName): void
    {
        $this->connectionName = $connectionName;
    }
}

class QueueJobFailedHookStub
{
    public function __construct(protected InvalidPayloadException $exception)
    {
    }

    public function failed(array $data, ?Throwable $e, string $uuid, Job $job): never
    {
        throw $this->exception;
    }
}

class QueueJobCancelingFailedHookStub
{
    public function __construct(protected Channel $gate, protected ?RuntimeException $cleanupException = null)
    {
    }

    public function failed(array $data, ?Throwable $e, string $uuid, Job $job): never
    {
        try {
            $this->gate->push(true);

            throw new RuntimeException('Cancellation was not delivered.');
        } finally {
            if ($this->cleanupException !== null) {
                Coroutine::sleep(0.001);
                throw $this->cleanupException;
            }
        }
    }
}

class QueueJobTimeoutBatchStub
{
    use Batchable;
}
