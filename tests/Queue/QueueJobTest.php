<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\TimeoutExceededException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class QueueJobTest extends TestCase
{
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
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (JobFailed $event) => $event->job === $job && $event->exception === $exception);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(Dispatcher::class)->andReturn($events);

        $job->setContainer($container);
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
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (JobFailed $event) => $event->job === $job && $event->exception === $failure);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')
            ->once()
            ->with(QueueJobFailedHookStub::class)
            ->andReturn(new QueueJobFailedHookStub($hookException));
        $container->shouldReceive('make')->once()->with(Dispatcher::class)->andReturn($events);

        $job->setContainer($container);
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

    public function testBatchRollbackFailurePreservesTimeoutForFailedJobCleanup(): void
    {
        $job = new QueueJobPayloadStub(json_encode([
            'uuid' => 'job-uuid',
            'job' => QueueJobTimeoutBatchStub::class,
            'data' => ['commandName' => QueueJobTimeoutBatchStub::class],
        ], JSON_THROW_ON_ERROR));
        $timeout = TimeoutExceededException::forJob($job);

        $batchRepository = m::mock(BatchRepository::class);
        $batchRepository->shouldReceive('rollBack')
            ->once()
            ->andThrow(new RuntimeException('Batch rollback failed.'));

        $config = new ConfigRepository([
            'queue' => [
                'failed' => [
                    'database' => 'sqlite',
                    'driver' => 'database',
                ],
            ],
        ]);
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('rollBack')->once()->with(0);
        $database = m::mock(ConnectionResolverInterface::class);
        $database->shouldReceive('connection')->once()->with('sqlite')->andReturn($connection);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (JobFailed $event) => $event->job === $job && $event->exception === $timeout);

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with(BatchRepository::class)->andReturn($batchRepository);
        $container->shouldReceive('make')->with('config')->andReturn($config);
        $container->shouldReceive('bound')->once()->with('db')->andReturnTrue();
        $container->shouldReceive('make')->once()->with('db')->andReturn($database);
        $container->shouldReceive('make')
            ->once()
            ->with(QueueJobTimeoutBatchStub::class)
            ->andReturn(new QueueJobTimeoutBatchStub);
        $container->shouldReceive('make')->once()->with(Dispatcher::class)->andReturn($events);

        $job->setContainer($container);
        $job->setConnectionName('redis');

        $job->fail($timeout);
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

        throw_if($this->rawBodyException !== null, $this->rawBodyException);

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

class QueueJobTimeoutBatchStub
{
    use Batchable;
}
