<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Carbon\CarbonImmutable;
use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchRepository;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Queue\Console\RetryBatchCommand;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryBatchCommandTest extends TestCase
{
    public function testItDoesNotFallThroughWhenBatchCannotBeFound()
    {
        $repo = m::mock(BatchRepository::class);
        $repo->shouldReceive('find')->once()->with('missing-batch')->andReturn(null);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new RetryBatchCommand;
        $command->setHypervel($this->app);

        $output = new BufferedOutput;

        $command->run(new ArrayInput(['id' => ['missing-batch']]), $output);

        $this->assertStringContainsString('Unable to find a batch with ID [missing-batch].', $output->fetch());
    }

    public function testItDoesNotRetryWhenBatchHasNoFailedJobs()
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

        $command->run(new ArrayInput(['id' => ['empty-batch']]), $output);

        $rendered = $output->fetch();

        $this->assertStringContainsString('The given batch does not contain any failed jobs.', $rendered);
        $this->assertStringNotContainsString('Pushing failed queue jobs of the batch [empty-batch] back onto the queue.', $rendered);
    }
}
