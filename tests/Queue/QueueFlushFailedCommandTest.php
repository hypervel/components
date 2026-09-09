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
    public function testFlushesFailedJobs(array $options, ?int $hours, string $message): void
    {
        $provider = m::mock(FailedJobProviderInterface::class);
        $provider->expects('flush')->with($hours);
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
            [[], null, 'All failed jobs deleted successfully.'],
            [['--hours' => '48'], 48, 'All jobs that failed more than 48 hours ago have been deleted successfully.'],
        ];
    }
}
