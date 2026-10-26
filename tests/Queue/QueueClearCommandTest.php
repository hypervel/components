<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UnitEnum;

class QueueClearCommandTest extends TestCase
{
    public function testClearingDefaultQueue(): void
    {
        $queue = new FakeClearableQueue(['default' => 2]);

        $output = $this->runClearCommand($queue);

        $this->assertStringContainsString('Cleared 2 jobs from the [default] queue', $output);
        $this->assertSame(['default'], $queue->cleared);
    }

    public function testClearingMultipleQueues(): void
    {
        $queue = new FakeClearableQueue(['high' => 3, 'low' => 0, 'emails' => 1]);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,low,emails']);

        $this->assertStringContainsString('Cleared 4 jobs from the [high, low, emails] queues', $output);
        $this->assertSame(['high', 'low', 'emails'], $queue->cleared);
    }

    public function testClearingMultipleQueuesWithWhitespace(): void
    {
        $queue = new FakeClearableQueue(['high' => 3, 'low' => 0]);

        $output = $this->runClearCommand($queue, ['--queue' => 'high, low']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
        $this->assertSame(['high', 'low'], $queue->cleared);
    }

    public function testClearingMultipleQueuesWithEmptyValues(): void
    {
        $queue = new FakeClearableQueue(['high' => 3, 'low' => 0]);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,,low']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
        $this->assertSame(['high', 'low'], $queue->cleared);
    }

    public function testClearingMultipleQueuesWithDuplicates(): void
    {
        $queue = new FakeClearableQueue(['high' => 3, 'low' => 0]);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,low,high']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
        $this->assertSame(['high', 'low'], $queue->cleared);
    }

    public function testClearingDistinctNumericQueueNames(): void
    {
        $queue = new FakeClearableQueue(['0' => 1, '01' => 2, '1' => 3]);

        $output = $this->runClearCommand($queue, ['--queue' => '0,01,1,0,01,1']);

        $this->assertStringContainsString('Cleared 6 jobs from the [0, 01, 1] queues', $output);
        $this->assertSame(['0', '01', '1'], $queue->cleared);
    }

    public function testProhibitedCommandCannotBeForced(): void
    {
        ClearCommand::prohibit();

        $container = new Application;
        $queueManager = m::mock(QueueManager::class);
        $queueManager->shouldNotReceive('connection');
        $container->instance('queue', $queueManager);

        $command = new ClearCommand;
        $command->setHypervel($container);
        $output = new BufferedOutput;

        $this->assertSame(ClearCommand::FAILURE, $command->run(new ArrayInput(['--force' => true]), $output));
        $this->assertStringContainsString('This command is prohibited', $output->fetch());
    }

    /**
     * Run the queue clear command and return its output.
     */
    protected function runClearCommand(Queue&ClearableQueue $queue, array $arguments = []): string
    {
        $container = new Application;
        $container->instance('env', 'testing');

        $config = m::mock(Repository::class);
        $config->expects('string')->with('queue.default')->andReturn('redis');
        $config->shouldReceive('string')->with('queue.connections.redis.queue', 'default')->andReturn('default');

        $container->instance('config', $config);

        $queueManager = m::mock(QueueManager::class);
        $queueManager->expects('connection')->with('redis')->andReturn($queue);

        $container->instance('queue', $queueManager);

        $command = new ClearCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput;
        $this->assertSame(ClearCommand::SUCCESS, $command->run(new ArrayInput($arguments), $output));

        return $output->fetch();
    }
}

class FakeClearableQueue extends NullQueue implements ClearableQueue
{
    public array $cleared = [];

    /**
     * Create a new fake clearable queue.
     *
     * @param array<string, int> $counts
     */
    public function __construct(protected array $counts)
    {
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(UnitEnum|string|null $queue): int
    {
        $this->cleared[] = $queue;

        return $this->counts[$queue] ?? 0;
    }
}
