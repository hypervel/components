<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\FlushFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueFlushFailedCommandTest extends TestCase
{
    public function testProhibitedCommandDoesNotFlushFailedJobs(): void
    {
        FlushFailedCommand::prohibit();

        $provider = m::mock(FailedJobProviderInterface::class);
        $provider->shouldNotReceive('flush');
        $app = new Application;
        $app->instance(FailedJobProviderInterface::class, $provider);
        $command = new FlushFailedCommand;
        $command->setHypervel($app);
        $output = new BufferedOutput;

        $this->assertSame(FlushFailedCommand::FAILURE, $command->run(new ArrayInput([]), $output));
        $this->assertStringContainsString('This command is prohibited', $output->fetch());
    }

    #[DataProvider('retentionOptions')]
    public function testFlushesFailedJobs(array $options, ?int $hours, ?string $queue, string $message): void
    {
        $provider = m::mock(FailedJobProviderInterface::class);
        $provider->expects('flush')->with($hours, $queue);
        $app = new Application;
        $app->instance(FailedJobProviderInterface::class, $provider);
        $command = new FlushFailedCommand;
        $command->setHypervel($app);
        $output = new BufferedOutput;

        $this->assertSame(FlushFailedCommand::SUCCESS, $command->run(new ArrayInput($options), $output));
        $this->assertStringContainsString($message, $output->fetch());
    }

    /**
     * Provide failed job retention options and their completion messages.
     */
    public static function retentionOptions(): array
    {
        return [
            [[], null, null, 'All failed jobs deleted successfully.'],
            [['--hours' => '48'], 48, null, 'All jobs that failed more than 48 hours ago have been deleted successfully.'],
            [['--queue' => 'emails'], null, 'emails', 'All failed jobs on the [emails] queue have been deleted successfully.'],
            [['--queue' => '0'], null, '0', 'All failed jobs on the [0] queue have been deleted successfully.'],
            [['--hours' => '48', '--queue' => 'emails'], 48, 'emails', 'All jobs on the [emails] queue that failed more than 48 hours ago have been deleted successfully.'],
        ];
    }
}
