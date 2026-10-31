<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Console\Command;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ForgetFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueForgetFailedCommandTest extends TestCase
{
    public function testItForgetsASingleJob(): void
    {
        $container = new Application;
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('forget')->with('5')->andReturnTrue();
        $container->instance(FailedJobProviderInterface::class, $failer);

        $command = new ForgetFailedCommand;
        $command->setHypervel($container);

        $exitCode = $command->run(new ArrayInput(['id' => ['5']]), $output = new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Failed job [5] deleted successfully.', $output->fetch());
    }

    public function testItForgetsMultipleJobs(): void
    {
        $container = new Application;
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('forget')->with('5')->andReturnTrue();
        $failer->shouldReceive('forget')->with('6')->andReturnTrue();
        $container->instance(FailedJobProviderInterface::class, $failer);

        $command = new ForgetFailedCommand;
        $command->setHypervel($container);

        $exitCode = $command->run(new ArrayInput(['id' => ['5', '6']]), $output = new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $output = $output->fetch();

        $this->assertStringContainsString('Failed job [5] deleted successfully.', $output);
        $this->assertStringContainsString('Failed job [6] deleted successfully.', $output);
    }

    public function testItFailsWhenAJobCannotBeFound(): void
    {
        $container = new Application;
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('forget')->with('5')->andReturnFalse();
        $container->instance(FailedJobProviderInterface::class, $failer);

        $command = new ForgetFailedCommand;
        $command->setHypervel($container);

        $exitCode = $command->run(new ArrayInput(['id' => ['5']]), $output = new BufferedOutput);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No failed job matches the ID [5].', $output->fetch());
    }

    public function testItForgetsTheRemainingJobsWhenOneCannotBeFound(): void
    {
        $container = new Application;
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('forget')->with('5')->andReturnTrue();
        $failer->shouldReceive('forget')->with('6')->andReturnFalse();
        $failer->shouldReceive('forget')->with('7')->andReturnTrue();
        $container->instance(FailedJobProviderInterface::class, $failer);

        $command = new ForgetFailedCommand;
        $command->setHypervel($container);

        $exitCode = $command->run(new ArrayInput(['id' => ['5', '6', '7']]), $output = new BufferedOutput);

        $this->assertSame(Command::FAILURE, $exitCode);

        $output = $output->fetch();

        $this->assertStringContainsString('Failed job [5] deleted successfully.', $output);
        $this->assertStringContainsString('No failed job matches the ID [6].', $output);
        $this->assertStringContainsString('Failed job [7] deleted successfully.', $output);
    }
}
