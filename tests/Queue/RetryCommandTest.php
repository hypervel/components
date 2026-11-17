<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Closure;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Events\JobRetryRequested;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SqsQueue;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UnitEnum;

class RetryCommandTest extends TestCase
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

        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->with('failed-id')->returns(true);

        $rawPayload = null;
        $queue = Double::for(QueueContract::class)->strict();
        $queue->expects('pushRaw')
            ->with(Argument::capture($rawPayload), 'critical', Argument::any())
            ->returns('retried-id');

        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('redis')->returns($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);
        $retryRequested = null;
        $this->app->make('events')->listen(
            JobRetryRequested::class,
            static function (JobRetryRequested $event) use (&$retryRequested): void {
                $retryRequested = $event;
            },
        );

        $command = new RetryCommand;
        $command->setHypervel($this->app);
        $command->run(
            new ArrayInput(['id' => ['failed-id']]),
            new BufferedOutput,
        );

        $expected = $payload;
        $expected['attempts'] = 0;
        $expected['retryUntil'] = 987654321;

        $this->assertSame($expected, json_decode($rawPayload, true));
        $this->assertSame($failedJob, $retryRequested->job);
    }

    public function testPassiveObserverDoesNotCauseRetryRequestedEventToDispatch(): void
    {
        $payload = json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'data' => [],
        ], JSON_THROW_ON_ERROR);
        $failedJob = $this->failedJob($payload);
        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->with('failed-id')->returns(true);
        $queue = new NullQueue;
        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('sqs')->returns($queue);
        $observed = false;
        $this->app->make('events')->observe(
            JobRetryRequested::class,
            static function () use (&$observed): void {
                $observed = true;
            },
        );
        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();

        $this->assertFalse($observed);
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
        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->with('failed-id')->returns(true);

        $handler = new MockHandler;
        $handler->append(new Result(['MessageId' => 'retried-id']));
        $sqs = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $handler,
        ]);

        $queue = new SqsQueue($sqs, 'jobs.fifo');
        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('sqs')->returns($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();

        $this->assertCount(0, $handler);
        $this->assertSame('SendMessage', $handler->getLastCommand()->getName());
        $message = $handler->getLastCommand()->toArray();
        $this->assertSame('0', $message['MessageGroupId']);
        $this->assertSame('0', $message['MessageDeduplicationId']);
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
        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->with('failed-id')->returns(true);

        $handler = new MockHandler;
        $handler->append(new Result(['MessageId' => 'retried-id']));
        $sqs = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $handler,
        ]);
        $queue = $this->pooledQueue('sqs', fn () => new SqsQueue($sqs, 'jobs.fifo'));

        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('sqs')->returns($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();

        $this->assertCount(0, $handler);
        $this->assertSame('SendMessage', $handler->getLastCommand()->getName());
        $message = $handler->getLastCommand()->toArray();
        $this->assertSame('0', $message['MessageGroupId']);
        $this->assertSame('0', $message['MessageDeduplicationId']);
        $this->assertSame(0, json_decode($message['MessageBody'], true, flags: JSON_THROW_ON_ERROR)['attempts']);
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
        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->with('failed-id')->returns(true);

        $beanstalkd = new RetryCommandPooledQueue;
        $queue = $this->pooledQueue('beanstalkd', fn () => $beanstalkd);
        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('beanstalkd')->returns($queue);

        $this->app->instance('queue.failer', $failedJobs);
        $this->app->instance('queue', $queueManager);

        $this->runRetryCommand();

        $this->assertTrue($beanstalkd->pushed);
        $this->assertSame([], $beanstalkd->options);
    }

    public function testSuccessfulSyncRetryExecutesBeforeFailedRecordIsForgotten(): void
    {
        RetryCommandSyncHandler::$handled = false;
        $failedJob = $this->failedJob(json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'job' => RetryCommandSyncHandler::class,
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $failedJob->connection = 'sync';
        $failedJob->queue = 'sync';

        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')
            ->with('failed-id')
            ->resolves(function (): bool {
                $this->assertTrue(RetryCommandSyncHandler::$handled);

                return true;
            });

        $this->app->instance('queue.failer', $failedJobs);

        try {
            $this->runRetryCommand();

            $this->assertTrue(RetryCommandSyncHandler::$handled);
        } finally {
            RetryCommandSyncHandler::$handled = false;
        }
    }

    public function testFailedSyncRetryPreservesFailedRecord(): void
    {
        $failedJob = $this->failedJob(json_encode([
            'uuid' => 'job-uuid',
            'attempts' => 3,
            'job' => FailingRetryCommandSyncHandler::class,
            'data' => [],
        ], JSON_THROW_ON_ERROR));
        $failedJob->connection = 'sync';
        $failedJob->queue = 'sync';

        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->never();

        $this->app->instance('queue.failer', $failedJobs);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Sync retry failed.');

        $this->runRetryCommand();
    }

    public function testMalformedPayloadIsNotForgottenWhenRetryFails(): void
    {
        $failedJob = $this->failedJob('{invalid');
        $failedJobs = Double::for(FailedJobProviderInterface::class)->strict();
        $failedJobs->expects('find')->with('failed-id')->returns($failedJob);
        $failedJobs->expects('forget')->never();

        $queue = Double::for(QueueContract::class)->strict();
        $queue->expects('pushRaw')->never();

        $queueManager = Double::for(QueueManager::class)->strict();
        $queueManager->expects('connection')->with('sqs')->returns($queue);

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
    protected function pooledQueue(string $resourceType, Closure $createCallback): QueuePoolProxy
    {
        $this->poolManagers[] = $poolManager = new PoolManager;

        return new QueuePoolProxy(
            new PoolDefinition(
                "retry-command-{$resourceType}",
                $resourceType,
                "auto:retry-command-{$resourceType}",
                PoolOptions::fromArray(['max_objects' => 1]),
            ),
            $createCallback,
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

    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        $this->pushed = true;
        $this->options = $options;

        return 'retried-id';
    }
}

class RetryCommandSyncHandler
{
    public static bool $handled = false;

    public function fire(): void
    {
        static::$handled = true;
    }
}

class FailingRetryCommandSyncHandler
{
    public function fire(): never
    {
        throw new RuntimeException('Sync retry failed.');
    }
}
