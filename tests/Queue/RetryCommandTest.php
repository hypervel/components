<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\QueueManager;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryCommandTest extends TestCase
{
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
}

class RetryCommandPayloadJob
{
    public function retryUntil(): int
    {
        return 987654321;
    }
}
