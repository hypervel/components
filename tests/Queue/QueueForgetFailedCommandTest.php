<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Console\Command;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ForgetFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueForgetFailedCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testItForgetsASingleJob(): void
    {
        $container = new Application;
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('forget')->with('5')->returns(true);
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
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('forget')->with('5')->returns(true);
        $failer->expects('forget')->with('6')->returns(true);
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
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('forget')->with('5')->returns(false);
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
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('forget')->with('5')->returns(true);
        $failer->expects('forget')->with('6')->returns(false);
        $failer->expects('forget')->with('7')->returns(true);
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
