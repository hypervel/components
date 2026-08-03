<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Closure;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SqsQueue;
use Hypervel\Testbench\TestCase;
use JsonException;
use Mockery as m;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryCommandTest extends TestCase
{
    /** @var list<PoolManager> */
    private array $poolManagers = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->poolManagers as $poolManager) {
            $poolManager->flush();
        }
    }

    public function testRetryPreservesArbitraryPayloadFields(): void
    {
        $payload = [
            'uuid' => 'job-uuid',
            'displayName' => RetryCommandPayloadJob::class,
            'attempts' => 4,
            'retryUntil' => 100,
            'custom' => [
                'nested' => [
                    'trace_id' => 'trace-123',
                    'flags' => [true, false],
                ],
            ],
            'illuminate:log:context' => [
                'data' => ['request_id' => serialize('request-123')],
                'hidden' => ['secret' => serialize('secret-value')],
            ],
            'data' => [
                'commandName' => RetryCommandPayloadJob::class,
                'command' => serialize(new RetryCommandPayloadJob),
                'application' => ['value' => 'preserved'],
            ],
        ];

        $failedJob = new stdClass;
        $failedJob->connection = 'redis';
        $failedJob->queue = 'critical';
        $failedJob->payload = json_encode($payload);

        $failedJobs = m::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('find')->once()->with('failed-id')->andReturn($failedJob);
        $failedJobs->shouldReceive('forget')->once()->with('failed-id')->andReturn(true);

        $pushedPayload = null;
        $queue = m::mock(QueueContract::class);
        $queue->shouldReceive('pushRaw')
            ->once()
            ->withArgs(function (string $rawPayload, string $queueName) use (&$pushedPayload): bool {
                $pushedPayload = json_decode($rawPayload, true);

                return $queueName === 'critical';
            })
            ->andReturn('retried-id');

        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->once()->with('redis')->andReturn($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $command = new RetryCommand;
        $command->setHypervel($this->app);
        $command->run(
            new ArrayInput(['id' => ['failed-id']]),
            new BufferedOutput,
        );

        $expected = $payload;
        $expected['attempts'] = 0;
        $expected['retryUntil'] = 987654321;

        $this->assertSame($expected, $pushedPayload);
    }

    public function testRetryPreservesFifoOptionsOnDirectSqsQueue(): void
    {
        $payload = json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'data' => [
                'command' => serialize(new RetryCommandFifoPayloadJob),
            ],
        ], JSON_THROW_ON_ERROR);

        $failedJob = $this->failedJob($payload);
        $failedJobs = m::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('find')->once()->with('failed-id')->andReturn($failedJob);
        $failedJobs->shouldReceive('forget')->once()->with('failed-id')->andReturn(true);

        $sqs = m::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (array $message): bool {
                return $message['MessageGroupId'] === '0'
                    && $message['MessageDeduplicationId'] === '0';
            })
            ->andReturn(new Result(['MessageId' => 'retried-id']));

        $queue = new SqsQueue($sqs, 'jobs.fifo');
        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->once()->with('sqs')->andReturn($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();
    }

    public function testRetryPreservesFifoOptionsThroughPooledSqsQueue(): void
    {
        $payload = json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'data' => [
                'command' => serialize(new RetryCommandFifoPayloadJob),
            ],
        ], JSON_THROW_ON_ERROR);

        $failedJob = $this->failedJob($payload);
        $failedJobs = m::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('find')->once()->with('failed-id')->andReturn($failedJob);
        $failedJobs->shouldReceive('forget')->once()->with('failed-id')->andReturn(true);

        $sqs = m::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (array $message): bool {
                return json_decode($message['MessageBody'], true, flags: JSON_THROW_ON_ERROR)['attempts'] === 0
                    && $message['MessageGroupId'] === '0'
                    && $message['MessageDeduplicationId'] === '0';
            })
            ->andReturn(new Result(['MessageId' => 'retried-id']));
        $queue = $this->pooledQueue('sqs', fn () => new SqsQueue($sqs, 'jobs.fifo'));

        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->once()->with('sqs')->andReturn($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();
    }

    public function testRetryDoesNotReadSqsOptionsFromPooledBeanstalkdQueue(): void
    {
        $payload = json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'data' => [
                'command' => serialize(new RetryCommandFifoPayloadJob),
            ],
        ], JSON_THROW_ON_ERROR);

        $failedJob = $this->failedJob($payload);
        $failedJob->connection = 'beanstalkd';
        $failedJobs = m::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('find')->once()->with('failed-id')->andReturn($failedJob);
        $failedJobs->shouldReceive('forget')->once()->with('failed-id')->andReturn(true);

        $beanstalkd = new RetryCommandPooledQueue;
        $queue = $this->pooledQueue('beanstalkd', fn () => $beanstalkd);
        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->once()->with('beanstalkd')->andReturn($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();

        $this->assertTrue($beanstalkd->pushed);
        $this->assertSame([], $beanstalkd->options);
    }

    public function testMalformedPayloadIsNotForgottenWhenRetryFails(): void
    {
        $failedJob = $this->failedJob('{invalid');
        $failedJobs = m::mock(FailedJobProviderInterface::class);
        $failedJobs->shouldReceive('find')->once()->with('failed-id')->andReturn($failedJob);
        $failedJobs->shouldNotReceive('forget');

        $queue = m::mock(QueueContract::class);
        $queue->shouldNotReceive('pushRaw');

        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->once()->with('sqs')->andReturn($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->expectException(JsonException::class);

        $this->runRetryCommand();
    }

    /**
     * Create a failed job record for the given payload.
     */
    protected function failedJob(string $payload): stdClass
    {
        $failedJob = new stdClass;
        $failedJob->connection = 'sqs';
        $failedJob->queue = 'jobs.fifo';
        $failedJob->payload = $payload;

        return $failedJob;
    }

    /**
     * Run the retry command for the test failed job.
     */
    protected function runRetryCommand(): void
    {
        $command = new RetryCommand;
        $command->setHypervel($this->app);
        $command->run(
            new ArrayInput(['id' => ['failed-id']]),
            new BufferedOutput,
        );
    }

    /**
     * Create a queue proxy with an isolated pool registry.
     */
    protected function pooledQueue(string $resourceType, Closure $resolver): QueuePoolProxy
    {
        $this->poolManagers[] = $poolManager = new PoolManager;

        return new QueuePoolProxy(
            new PoolDefinition(
                "retry-command-{$resourceType}",
                $resourceType,
                "auto:retry-command-{$resourceType}",
                PoolOptions::fromArray(['max_objects' => 1]),
            ),
            $resolver,
            $poolManager,
        );
    }
}

class RetryCommandPayloadJob
{
    public function retryUntil(): int
    {
        return 987654321;
    }
}

class RetryCommandFifoPayloadJob
{
    public function messageGroup(): string
    {
        return '0';
    }

    public function deduplicationId(): string
    {
        return '0';
    }
}

class RetryCommandPooledQueue extends NullQueue
{
    public array $options = [];

    public bool $pushed = false;

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $this->pushed = true;
        $this->options = $options;

        return 'retried-id';
    }
}
