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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorCommandTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('outputDecoration')]
    public function testItDisplaysQueueMetricsAsJsonAndDispatchesBusyEvent(bool $decorated): void
    {
        $name = '<info>Queue\name</info> \<error>literal</error>';
        $queue = Double::for(Queue::class)->strict();
        $queue->allows('size')->with($name)->returns(1001);
        $queue->allows('pendingSize')->with($name)->returns(0);
        $queue->allows('delayedSize')->with($name)->returns(2);
        $queue->allows('reservedSize')->with($name)->returns(3);
        $queue->allows('creationTimeOfOldestPendingJob')->with($name)->returns(null);

        $manager = Double::for(Factory::class)->strict();
        $manager->allows('connection')->with('redis')->returns($queue);

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(QueueBusy::class)->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(fn (mixed $event): bool => $event instanceof QueueBusy
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
        $queue = Double::for(Queue::class)->strict();
        $queue->allows('size')->with('default')->returns(1);
        $queue->allows('pendingSize')->with('default')->returns(1);
        $queue->allows('delayedSize')->with('default')->returns(0);
        $queue->allows('reservedSize')->with('default')->returns(0);
        $queue->allows('creationTimeOfOldestPendingJob')->with('default')->returns(null);

        $manager = Double::for(Factory::class)->strict();
        $manager->allows('connection')->with('redis')->returns($queue);

        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(QueueBusy::class)->returns(false);
        $events->expects('dispatch')->never();

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
