<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Queue\Console\WorkCommand;
use Hypervel\Queue\Events\WorkerStopping;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Queue\WorkerStopReason;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class WorkCommandTest extends TestCase
{
    /**
     * Configure the command's application environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('queue.default', 'sync');
        $config->set('cache.default', 'array');
    }

    public function testStopOutputUsesTheCurrentCommand(): void
    {
        $this->travelTo(CarbonImmutable::create(2023, 1, 18, 10, 10, 11));

        $firstOutput = new BufferedOutput;
        $this->runWorkerCommand(new WorkerStopping(reason: WorkerStopReason::QueueEmpty), $firstOutput);

        $this->assertSame("  2023-01-18 10:10:11 Worker STOPPED Queue empty\n", $firstOutput->fetch());

        $secondOutput = new BufferedOutput;
        $this->runWorkerCommand(new WorkerStopping(
            status: Worker::EXIT_MEMORY_LIMIT,
            reason: WorkerStopReason::MaxMemoryExceeded,
            jobsProcessed: 0,
            memoryUsage: 64.25,
        ), $secondOutput, ['--json' => true]);

        $this->assertSame('', $firstOutput->fetch());
        $this->assertSame([
            'level' => 'warning',
            'status' => 'stopped',
            'reason' => 'memory',
            'exit_code' => 12,
            'jobs_processed' => 0,
            'memory' => 64.3,
            'timestamp' => '2023-01-18T10:10:11.000000+00:00',
        ], json_decode($secondOutput->fetch(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStopOutputPreservesMissingMetrics(): void
    {
        $this->travelTo(CarbonImmutable::create(2023, 1, 18, 10, 10, 11));

        $output = new BufferedOutput;
        $this->runWorkerCommand(new WorkerStopping(reason: WorkerStopReason::QueueEmpty), $output, ['--json' => true]);

        $this->assertSame([
            'level' => 'info',
            'status' => 'stopped',
            'reason' => 'empty',
            'exit_code' => 0,
            'jobs_processed' => null,
            'memory' => null,
            'timestamp' => '2023-01-18T10:10:11.000000+00:00',
        ], json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('suppressedStopOutputProvider')]
    public function testStopOutputIsSuppressed(int $verbosity, ?WorkerStopReason $reason): void
    {
        $output = new BufferedOutput($verbosity);
        $this->runWorkerCommand(new WorkerStopping(reason: $reason), $output, ['--json' => true]);

        $this->assertSame('', $output->fetch());
    }

    /**
     * Provide stop events that should not produce output.
     */
    public static function suppressedStopOutputProvider(): array
    {
        return [
            'quiet' => [OutputInterface::VERBOSITY_QUIET, WorkerStopReason::QueueEmpty],
            'silent' => [OutputInterface::VERBOSITY_SILENT, WorkerStopReason::QueueEmpty],
            'no reason' => [OutputInterface::VERBOSITY_NORMAL, null],
        ];
    }

    public function testStopEventsWithoutCommandOptionsDoNotWriteOutput(): void
    {
        $output = new BufferedOutput;
        $this->runWorkerCommand(new WorkerStopping(reason: WorkerStopReason::QueueEmpty), $output, ['--json' => true]);
        $output->fetch();

        $this->app->make('events')->dispatch(new WorkerStopping(reason: WorkerStopReason::QueueEmpty));

        $this->assertSame('', $output->fetch());
    }

    /**
     * Run a distinct command instance that dispatches the given stop event.
     *
     * @param array<string, bool> $arguments
     */
    private function runWorkerCommand(WorkerStopping $event, BufferedOutput $output, array $arguments = []): void
    {
        $worker = m::mock(Worker::class);
        $worker->shouldReceive('setName')->once()->with('default')->andReturnSelf();
        $worker->shouldReceive('setCache')->once()->with(m::type(Repository::class))->andReturnSelf();
        $worker->shouldReceive('daemon')
            ->once()
            ->with('sync', 'default', m::type(WorkerOptions::class))
            ->andReturnUsing(function (string $connection, string $queue, WorkerOptions $options) use ($event): int {
                $event->workerOptions = $options;
                $this->app->make('events')->dispatch($event);

                return $event->status;
            });

        $command = new WorkCommand(
            $this->app,
            $this->app->make('config'),
            $worker,
            $this->app->make('cache'),
        );
        $command->setHypervel($this->app);
        $command->run(new ArrayInput($arguments), $output);
    }
}
