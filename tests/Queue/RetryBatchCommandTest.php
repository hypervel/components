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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetryBatchCommandTest extends TestCase
{
    use VerifiesDoubles;

    #[TestWith(['missing-batch'])]
    #[TestWith(['0'])]
    public function testItFailsWhenTheBatchCannotBeFound(string $id): void
    {
        $repo = Double::for(BatchRepository::class);
        $repo->expects('find')->with($id)->returns(null);

        $this->app->instance(BatchRepository::class, $repo);

        $command = new RetryBatchCommand;
        $command->setHypervel($this->app);

        $output = new BufferedOutput;

        $exitCode = $command->run(new ArrayInput(['id' => [$id]]), $output);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("Unable to find a batch with ID [{$id}].", $output->fetch());
    }

    public function testItFailsWhenTheBatchHasNoFailedJobs(): void
    {
        $batch = new Batch(
            Double::for(QueueFactory::class),
            Double::for(BatchRepository::class),
            'empty-batch',
            'Empty Batch',
            0,
            0,
            0,
            [],
            [],
            CarbonImmutable::now(),
        );

        $repo = Double::for(BatchRepository::class);
        $repo->expects('find')->with('empty-batch')->returns($batch);

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
        $repo = Double::for(BatchRepository::class);
        $repo->expects('find')->with('batch-id')->returns(null);

        $this->app->instance(BatchRepository::class, $repo);

        $mutex = Double::for(CommandMutex::class);
        $mutex->expects('create')->returns(true);
        $mutex->expects('forget')->returns(true);

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
