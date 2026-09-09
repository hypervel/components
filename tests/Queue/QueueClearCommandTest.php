<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\QueueManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueClearCommandTest extends TestCase
{
    public function testClearingDefaultQueue(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('default')->andReturn(2);

        $output = $this->runClearCommand($queue);

        $this->assertStringContainsString('Cleared 2 jobs from the [default] queue', $output);
    }

    public function testClearingMultipleQueues(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('high')->andReturn(3);
        $queue->expects('clear')->with('low')->andReturn(0);
        $queue->expects('clear')->with('emails')->andReturn(1);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,low,emails']);

        $this->assertStringContainsString('Cleared 4 jobs from the [high, low, emails] queues', $output);
    }

    public function testClearingMultipleQueuesWithWhitespace(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('high')->andReturn(3);
        $queue->expects('clear')->with('low')->andReturn(0);

        $output = $this->runClearCommand($queue, ['--queue' => 'high, low']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
    }

    public function testClearingMultipleQueuesWithEmptyValues(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('high')->andReturn(3);
        $queue->expects('clear')->with('low')->andReturn(0);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,,low']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
    }

    public function testClearingMultipleQueuesWithDuplicates(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('high')->andReturn(3);
        $queue->expects('clear')->with('low')->andReturn(0);

        $output = $this->runClearCommand($queue, ['--queue' => 'high,low,high']);

        $this->assertStringContainsString('Cleared 3 jobs from the [high, low] queues', $output);
    }

    public function testClearingDistinctNumericQueueNames(): void
    {
        $queue = m::mock(Queue::class, ClearableQueue::class);
        $queue->expects('clear')->with('0')->andReturn(1);
        $queue->expects('clear')->with('01')->andReturn(2);
        $queue->expects('clear')->with('1')->andReturn(3);

        $output = $this->runClearCommand($queue, ['--queue' => '0,01,1,0,01,1']);

        $this->assertStringContainsString('Cleared 6 jobs from the [0, 01, 1] queues', $output);
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
