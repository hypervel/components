<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\MonitorCommand;
use Hypervel\Queue\Events\QueueBusy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MonitorCommandTest extends TestCase
{
    public function testItDisplaysQueueMetricsAsJsonAndDispatchesBusyEvent(): void
    {
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('size')->with('default')->andReturn(1001);
        $queue->shouldReceive('pendingSize')->with('default')->andReturn(0);
        $queue->shouldReceive('delayedSize')->with('default')->andReturn(2);
        $queue->shouldReceive('reservedSize')->with('default')->andReturn(3);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->with('default')->andReturn(null);

        $manager = m::mock(Factory::class);
        $manager->shouldReceive('connection')->with('redis')->andReturn($queue);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(fn (mixed $event): bool => $event instanceof QueueBusy
                && $event->connectionName === 'redis'
                && $event->queue === 'default'
                && $event->size === 1001));

        $output = $this->runCommand(
            new MonitorCommand($manager, $events, new Repository(['queue' => ['default' => 'sync']])),
            ['queues' => 'redis:default', '--json' => true],
        );

        $this->assertJsonStringEqualsJsonString(
            '[{"connection":"redis","queue":"default","size":1001,"pending":0,"delayed":2,"reserved":3,"oldest_pending":null,"status":"ALERT"}]',
            $output,
        );
    }

    public function testItDisplaysDetailedQueueMetricsForTheDefaultConnection(): void
    {
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('size')->with('default')->andReturn(1);
        $queue->shouldReceive('pendingSize')->with('default')->andReturn(1);
        $queue->shouldReceive('delayedSize')->with('default')->andReturn(0);
        $queue->shouldReceive('reservedSize')->with('default')->andReturn(0);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->with('default')->andReturn(null);

        $manager = m::mock(Factory::class);
        $manager->shouldReceive('connection')->with('redis')->andReturn($queue);

        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $output = $this->runCommand(
            new MonitorCommand($manager, $events, new Repository(['queue' => ['default' => 'redis']])),
            ['queues' => 'default'],
        );

        $this->assertStringContainsString('[redis] default', $output);
        $this->assertStringContainsString('Pending jobs', $output);
        $this->assertStringContainsString('Delayed jobs', $output);
        $this->assertStringContainsString('Reserved jobs', $output);
        $this->assertStringContainsString('Oldest pending job', $output);
        $this->assertStringContainsString('N/A', $output);
    }

    /**
     * Run the given monitor command.
     *
     * @param array<string, mixed> $arguments
     */
    private function runCommand(MonitorCommand $command, array $arguments): string
    {
        $command->setHypervel(new Application);

        $output = new BufferedOutput;
        $command->run(new ArrayInput($arguments), $output);

        return $output->fetch();
    }
}
