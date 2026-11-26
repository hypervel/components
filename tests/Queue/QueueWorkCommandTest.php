<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Engine\Channel;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Queue\Console\WorkCommand;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Worker;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 * @coversNothing
 */
class QueueWorkCommandTest extends TestCase
{
    use RunTestsInCoroutine;

    private const JOB_RUN_TIME = 0.05;

    public function testCliOutputReportsTheRunTimeOfAJobFailedFromAnotherCoroutine()
    {
        [$command, $output] = $this->command();
        $job = $this->job();

        $command->cli($job, 'starting');
        Coroutine::sleep(self::JOB_RUN_TIME);
        $this->inAnotherCoroutine(fn () => $command->cli($job, 'failed'));

        $this->assertSame(1, preg_match('/([\d.]+)ms\s+FAIL/', $output->fetch(), $matches));
        $this->assertGreaterThanOrEqual(self::JOB_RUN_TIME * 1000, (float) $matches[1]);
    }

    public function testJsonOutputReportsTheDurationOfAJobFailedFromAnotherCoroutine()
    {
        [$command, $output] = $this->command();
        $job = $this->job();

        $command->json($job, 'starting');
        Coroutine::sleep(self::JOB_RUN_TIME);
        $this->inAnotherCoroutine(fn () => $command->json($job, 'failed'));

        $lines = array_values(array_filter(explode("\n", $output->fetch())));
        $failed = json_decode($lines[1], true);

        $this->assertSame('failed', $failed['status']);
        $this->assertGreaterThanOrEqual(self::JOB_RUN_TIME, $failed['duration']);
        $this->assertLessThan(5, $failed['duration']);
    }

    /**
     * Run the callback the way the worker's timeout monitor does: in a coroutine
     * that shares no context with the one the job started in.
     */
    private function inAnotherCoroutine(callable $callback): void
    {
        $done = new Channel(1);

        Coroutine::create(function () use ($callback, $done) {
            try {
                $callback();
            } finally {
                $done->push(true);
            }
        });

        $done->pop(5);
    }

    /**
     * @return array{WorkCommandOutputStub, BufferedOutput}
     */
    private function command(): array
    {
        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')->andReturnNull();

        $command = new WorkCommandOutputStub(
            m::mock(ContainerInterface::class),
            $config,
            m::mock(Worker::class),
            m::mock(CacheFactory::class),
        );

        $output = new BufferedOutput();
        $command->writeTo(new SymfonyStyle(new ArrayInput([]), $output));

        return [$command, $output];
    }

    private function job(): Job
    {
        $job = m::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('FooJob');
        $job->shouldReceive('getJobId')->andReturn('1');
        $job->shouldReceive('uuid')->andReturn('fake-uu-id');
        $job->shouldReceive('getConnectionName')->andReturn('default');
        $job->shouldReceive('getQueue')->andReturn('queue');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('isDeleted', 'isReleased', 'hasFailed')->andReturnFalse();

        return $job;
    }
}

class WorkCommandOutputStub extends WorkCommand
{
    public function writeTo(SymfonyStyle $output): void
    {
        $this->output = $output;
    }

    public function cli(Job $job, string $status): void
    {
        $this->writeOutputForCli($job, $status);
    }

    public function json(Job $job, string $status): void
    {
        $this->writeOutputAsJson($job, $status);
    }
}
