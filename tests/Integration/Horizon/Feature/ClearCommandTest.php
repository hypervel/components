<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Horizon\Console\ClearCommand;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Queue\QueueManager;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ClearCommandTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('horizon.defaults', [
            'supervisor-1' => ['connection' => 'redis'],
        ]);
        $app['config']->set('queue.connections.redis.queue', 'default');
        $app['config']->set('queue.connections.0.queue', 'zero-default');
    }

    #[DataProvider('queueIdentifierProvider')]
    public function testCommandPreservesZeroAndDefaultsEmptyIdentifiers(
        string $connection,
        string $queue,
        string $expectedConnection,
        string $expectedQueue,
    ): void {
        $jobRepository = m::mock(RedisJobRepository::class);
        $jobRepository->shouldReceive('purge')->once()->with($expectedQueue);
        $this->app->instance(JobRepository::class, $jobRepository);

        $resolvedQueue = m::mock(Queue::class, ClearableQueue::class);
        $resolvedQueue->shouldReceive('clear')->once()->with($expectedQueue)->andReturn(1);

        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with($expectedConnection)->andReturn($resolvedQueue);
        $this->app->instance('queue', $manager);

        $command = new ClearCommand;
        $command->setHypervel($this->app);

        $this->assertSame(0, $command->run(
            new ArrayInput([
                'connection' => $connection,
                '--queue' => $queue,
                '--force' => true,
            ]),
            new BufferedOutput,
        ));
    }

    /**
     * Provide queue identifiers and their resolved values.
     */
    public static function queueIdentifierProvider(): array
    {
        return [
            'zero connection' => ['0', '', '0', 'zero-default'],
            'zero queue' => ['redis', '0', 'redis', '0'],
            'empty identifiers' => ['', '', 'redis', 'default'],
        ];
    }
}
