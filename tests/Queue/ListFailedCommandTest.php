<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ListFailedCommandTest extends TestCase
{
    public function testItDisplaysEmptyFailedJobsAsJson(): void
    {
        $output = $this->runCommandWithFailedJobs([], ['--json' => true]);

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString('[]', $output);
    }

    #[DataProvider('outputDecoration')]
    public function testItDisplaysFailedJobsAsJson(bool $decorated): void
    {
        $queue = '<info>Queue\name</info> \<error>literal</error>';
        $output = $this->runCommandWithFailedJobs([
            (object) [
                'id' => 'failed-job-id',
                'connection' => 'redis',
                'queue' => $queue,
                'payload' => json_encode([
                    'job' => 'Hypervel\Queue\CallQueuedHandler@call',
                    'data' => [
                        'command' => 'O:31:"Hypervel\Tests\Queue\ExampleJob":0:{}',
                    ],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'Exception stack trace',
                'failed_at' => '2026-05-18 12:00:00',
            ],
        ], ['--json' => true], $decorated);

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString(json_encode([
            [
                'id' => 'failed-job-id',
                'connection' => 'redis',
                'queue' => $queue,
                'class' => 'Hypervel\Tests\Queue\ExampleJob',
                'failed_at' => '2026-05-18 12:00:00',
            ],
        ], JSON_THROW_ON_ERROR), $output);
    }

    /**
     * Provide terminal and redirected output modes.
     */
    public static function outputDecoration(): array
    {
        return [[false], [true]];
    }

    #[TestWith(['emails'])]
    #[TestWith(['0'])]
    public function testItFiltersFailedJobsByQueue(string $queue): void
    {
        $payload = json_encode([
            'job' => 'Hypervel\Queue\CallQueuedHandler@call',
            'data' => [
                'command' => 'O:31:"Hypervel\Tests\Queue\ExampleJob":0:{}',
            ],
        ], JSON_THROW_ON_ERROR);

        $output = $this->runCommandWithFailedJobs([
            (object) [
                'id' => 'default-job-id',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => $payload,
                'exception' => 'Exception stack trace',
                'failed_at' => '2026-05-18 12:00:00',
            ],
            (object) [
                'id' => 'emails-job-id',
                'connection' => 'redis',
                'queue' => $queue,
                'payload' => $payload,
                'exception' => 'Exception stack trace',
                'failed_at' => '2026-05-18 12:05:00',
            ],
        ], ['--json' => true, '--queue' => $queue]);

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString(json_encode([
            [
                'id' => 'emails-job-id',
                'connection' => 'redis',
                'queue' => $queue,
                'class' => 'Hypervel\Tests\Queue\ExampleJob',
                'failed_at' => '2026-05-18 12:05:00',
            ],
        ], JSON_THROW_ON_ERROR), $output);
    }

    /**
     * Run the failed-job command with the given records.
     *
     * @param array<int, object> $failedJobs
     * @param array<string, mixed> $arguments
     */
    protected function runCommandWithFailedJobs(array $failedJobs, array $arguments = [], bool $decorated = false): string
    {
        $container = new Application;
        $failer = m::mock(FailedJobProviderInterface::class);
        $container->instance(FailedJobProviderInterface::class, $failer);

        $failer->expects('all')->andReturn($failedJobs);

        $command = new ListFailedCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);

        $command->run(new ArrayInput($arguments), $output);

        return $output->fetch();
    }
}
