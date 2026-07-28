<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ListFailedCommandTest extends TestCase
{
    public function testItDisplaysEmptyFailedJobsAsJson(): void
    {
        $output = $this->runCommandWithFailedJobs([], ['--json' => true]);

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString('[]', $output);
    }

    public function testItDisplaysFailedJobsAsJson(): void
    {
        $output = $this->runCommandWithFailedJobs([
            (object) [
                'id' => 'failed-job-id',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode([
                    'job' => 'Hypervel\Queue\CallQueuedHandler@call',
                    'data' => [
                        'command' => 'O:31:"Hypervel\Tests\Queue\ExampleJob":0:{}',
                    ],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'Exception stack trace',
                'failed_at' => '2026-05-18 12:00:00',
            ],
        ], ['--json' => true]);

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString(json_encode([
            [
                'id' => 'failed-job-id',
                'connection' => 'redis',
                'queue' => 'default',
                'class' => 'Hypervel\Tests\Queue\ExampleJob',
                'failed_at' => '2026-05-18 12:00:00',
            ],
        ], JSON_THROW_ON_ERROR), $output);
    }

    /**
     * @param array<int, object> $failedJobs
     * @param array<string, mixed> $arguments
     */
    protected function runCommandWithFailedJobs(array $failedJobs, array $arguments = []): string
    {
        $container = new Application;
        $container->instance(
            FailedJobProviderInterface::class,
            $failer = m::mock(FailedJobProviderInterface::class),
        );

        $failer->shouldReceive('all')->once()->andReturn($failedJobs);

        $command = new ListFailedCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput;

        $command->run(new ArrayInput($arguments), $output);

        return $output->fetch();
    }
}
