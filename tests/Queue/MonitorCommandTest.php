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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorCommandTest extends TestCase
{
    #[DataProvider('outputDecoration')]
    public function testItDisplaysQueueMetricsAsJsonAndDispatchesBusyEvent(bool $decorated): void
    {
        $name = '<info>Queue\name</info> \<error>literal</error>';
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('size')->with($name)->andReturn(1001);
        $queue->shouldReceive('pendingSize')->with($name)->andReturn(0);
        $queue->shouldReceive('delayedSize')->with($name)->andReturn(2);
        $queue->shouldReceive('reservedSize')->with($name)->andReturn(3);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->with($name)->andReturn(null);

        $manager = m::mock(Factory::class);
        $manager->shouldReceive('connection')->with('redis')->andReturn($queue);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(QueueBusy::class)->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->once()
            ->with(m::on(fn (mixed $event): bool => $event instanceof QueueBusy
                && $event->connectionName === 'redis'
                && $event->queue === $name
                && $event->size === 1001));

        $output = $this->runCommand(
            new MonitorCommand($manager, $events, new Repository(['queue' => ['default' => 'sync']])),
            ['queues' => 'redis:' . $name, '--json' => true],
            $decorated,
        );

        $this->assertSame([[
            'connection' => 'redis',
            'queue' => $name,
            'size' => 1001,
            'pending' => 0,
            'delayed' => 2,
            'reserved' => 3,
            'oldest_pending' => null,
            'status' => 'ALERT',
        ]], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Provide terminal and redirected output modes.
     */
    public static function outputDecoration(): array
    {
        return [[false], [true]];
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
        $events->shouldReceive('hasListeners')->once()->with(QueueBusy::class)->andReturnFalse();
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
    private function runCommand(MonitorCommand $command, array $arguments, bool $decorated = false): string
    {
        $command->setHypervel(new Application);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        $command->run(new ArrayInput($arguments), $output);

        return $output->fetch();
    }
}
