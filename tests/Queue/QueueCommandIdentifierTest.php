<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\Console\ListenCommand;
use Hypervel\Queue\Listener;
use Hypervel\Queue\QueueManager;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueCommandIdentifierTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis.queue', 'default');
        $app['config']->set('queue.connections.0.queue', 'zero-default');
    }

    #[DataProvider('queueIdentifierProvider')]
    public function testClearCommandPreservesZeroAndDefaultsEmptyIdentifiers(
        string $connection,
        string $queue,
        string $expectedConnection,
        string $expectedQueue,
    ): void {
        $resolvedQueue = m::mock(Queue::class, ClearableQueue::class);
        $resolvedQueue->shouldReceive('clear')->once()->with($expectedQueue)->andReturn(1);

        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with($expectedConnection)->andReturn($resolvedQueue);
        $this->app->instance('queue', $manager);

        $command = new ClearCommand;
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(
            new ArrayInput([
                'connection' => $connection,
                '--queue' => $queue,
                '--force' => true,
            ]),
            new BufferedOutput,
        ));
    }

    #[DataProvider('queueIdentifierProvider')]
    public function testListenCommandPreservesZeroAndDefaultsEmptyIdentifiers(
        string $connection,
        string $queue,
        string $expectedConnection,
        string $expectedQueue,
    ): void {
        $this->app['config']->set("queue.connections.{$expectedConnection}.queue", $expectedQueue);

        $listener = m::mock(Listener::class);
        $listener->shouldReceive('setOutputHandler')->once();

        $command = new QueueIdentifierListenCommand($this->app->make('config'), $listener);

        $this->assertSame($expectedQueue, $command->resolveQueue($connection, $queue));
    }

    /**
     * Provide queue identifiers and their resolved values.
     */
    public static function queueIdentifierProvider(): array
    {
        return [
            'zero connection' => ['0', '', '0', 'zero-default'],
            'zero queue' => ['redis', '0', 'redis', '0'],
            'empty identifiers' => ['', '', 'redis', 'default'],
        ];
    }
}

class QueueIdentifierListenCommand extends ListenCommand
{
    /**
     * Resolve the configured queue for the given command input.
     */
    public function resolveQueue(string $connection, string $queue): string
    {
        $this->input = new ArrayInput(['--queue' => $queue], $this->getDefinition());

        return $this->getQueue($connection);
    }
}
