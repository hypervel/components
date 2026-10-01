<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchRepository;
use Hypervel\Console\Command;
use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Queue\Console\RetryBatchCommand;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryBatchCommandTest extends TestCase
{
    public function testItFailsWhenTheBatchCannotBeFound(): void
    {
        $repo = m::mock(BatchRepository::class);
        $repo->shouldReceive('find')->once()->with('missing-batch')->andReturn(null);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new RetryBatchCommand;
        $command->setHypervel($this->app);

        $output = new BufferedOutput;

        $exitCode = $command->run(new ArrayInput(['id' => ['missing-batch']]), $output);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unable to find a batch with ID [missing-batch].', $output->fetch());
    }

    public function testItFailsWhenTheBatchHasNoFailedJobs(): void
    {
        $batch = new Batch(
            m::mock(QueueFactory::class),
            m::mock(BatchRepository::class),
            'empty-batch',
            'Empty Batch',
            0,
            0,
            0,
            [],
            [],
            CarbonImmutable::now(),
        );

        $repo = m::mock(BatchRepository::class);
        $repo->shouldReceive('find')->once()->with('empty-batch')->andReturn($batch);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new RetryBatchCommand;
        $command->setHypervel($this->app);

        $output = new BufferedOutput;

        $exitCode = $command->run(new ArrayInput(['id' => ['empty-batch']]), $output);

        $this->assertSame(Command::FAILURE, $exitCode);

        $rendered = $output->fetch();

        $this->assertStringContainsString('The batch with ID [empty-batch] does not contain any failed jobs.', $rendered);
        $this->assertStringNotContainsString('Pushing failed queue jobs of the batch', $rendered);
    }

    public function testItCanBeRunInIsolation(): void
    {
        $repo = m::mock(BatchRepository::class);
        $repo->shouldReceive('find')->once()->with('batch-id')->andReturn(null);

        $this->app->instance(BatchRepository::class, $repo);

        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnTrue();
        $mutex->shouldReceive('forget')->once()->andReturnTrue();

        $this->app->instance(CommandMutex::class, $mutex);

        $command = new RetryBatchCommand;
        $command->setHypervel($this->app);

        $exitCode = $command->run(
            new ArrayInput(['id' => ['batch-id'], '--isolated' => true]),
            new BufferedOutput
        );

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
