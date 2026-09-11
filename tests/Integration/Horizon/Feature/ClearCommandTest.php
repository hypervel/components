<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Horizon\Console\ClearCommand;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\RedisQueue;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Integration\Horizon\Feature\Jobs\BasicJob;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ClearCommandTest extends IntegrationTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('queue.connections.redis.queue', 'default');
        $config->set('database.redis.secondary', array_replace($config->array('database.redis.default'), [
            'prefix' => 'horizon_clear_secondary:',
        ]));
        $config->set('queue.connections.secondary', array_replace($config->array('queue.connections.redis'), [
            'connection' => 'secondary',
            'queue' => 'secondary-default',
        ]));
        $config->set('queue.connections.redis-long', array_replace($config->array('queue.connections.redis'), [
            'retry_after' => 3600,
        ]));
        $config->set('queue.connections.0.queue', 'zero-default');
    }

    #[DataProvider('queueIdentifierProvider')]
    public function testCommandPreservesZeroAndDefaultsEmptyIdentifiers(
        string $connection,
        string $queue,
        array $defaults,
        string $expectedConnection,
        string $expectedQueue,
    ): void {
        config()->set('horizon.defaults', $defaults);

        $jobRepository = m::mock(RedisJobRepository::class);
        $jobRepository->shouldReceive('purge')->once()->with($expectedQueue, $expectedConnection);
        $this->app->instance(JobRepository::class, $jobRepository);

        $resolvedQueue = m::mock(RedisQueue::class);
        $resolvedQueue->shouldReceive('getQueue')->once()->with($expectedQueue)->andReturn('queues:' . $expectedQueue);
        $resolvedQueue->shouldReceive('getConnectionName')->once()->andReturn($expectedConnection);
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
            'zero connection' => ['0', '', [], '0', 'zero-default'],
            'zero queue' => ['redis', '0', [], 'redis', '0'],
            'configured default' => ['', '', [
                'supervisor-1' => ['connection' => 'secondary'],
            ], 'secondary', 'secondary-default'],
            'omitted defaults' => ['', '', [], 'redis', 'default'],
        ];
    }

    public function testClearingAForwardedQueueRemovesItsDestinationMetadata(): void
    {
        // The second forward exposes accidentally resolving the destination twice.
        Queue::forward(['reports' => 'processing', 'processing' => 'archive']);
        $id = Queue::push(new BasicJob, queue: 'reports');
        $this->assertSame('processing', Redis::connection('horizon')->hget($id, 'queue'));

        $this->artisan('horizon:clear', ['connection' => 'redis', '--queue' => 'reports', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Queue::size('reports'));
        $this->assertSame(0, $this->recentJobs());
        $this->assertSame(0, Redis::connection('horizon')->exists($id));
    }

    public function testClearingOneConnectionPreservesAnotherConnectionsJobs(): void
    {
        $id = Queue::push(new BasicJob, queue: 'reports');
        $otherId = Queue::connection('secondary')->push(new BasicJob, queue: 'reports');

        $this->artisan('horizon:clear', ['connection' => 'redis', '--queue' => 'reports', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Queue::size('reports'));
        $this->assertSame(1, Queue::connection('secondary')->size('reports'));
        $this->assertSame(0, Redis::connection('horizon')->exists($id));
        $this->assertSame('pending', Redis::connection('horizon')->hget($otherId, 'status'));
    }

    public function testClearingSharedStoragePreservesOtherConnectionRecordsUntilTheyExpire(): void
    {
        $id = Queue::push(new BasicJob, queue: 'reports');
        $otherId = Queue::connection('redis-long')->push(new BasicJob, queue: 'reports');

        $this->artisan('horizon:clear', ['connection' => 'redis', '--queue' => 'reports', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Queue::connection('redis-long')->size('reports'));
        $this->assertSame(0, Redis::connection('horizon')->exists($id));
        $this->assertSame('pending', Redis::connection('horizon')->hget($otherId, 'status'));
        $this->assertGreaterThan(0, Redis::connection('horizon')->ttl($otherId));
    }

    public function testClearingANonHorizonConnectionDoesNotPurgeHorizonJobs(): void
    {
        $jobRepository = m::mock(JobRepository::class);
        $jobRepository->shouldNotReceive('purge');
        $this->app->instance(JobRepository::class, $jobRepository);

        $resolvedQueue = m::mock(QueueContract::class, ClearableQueue::class);
        $resolvedQueue->shouldReceive('clear')->once()->with('default')->andReturn(1);

        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with('redis')->andReturn($resolvedQueue);
        $this->app->instance('queue', $manager);

        $this->artisan('horizon:clear', ['connection' => 'redis', '--force' => true])
            ->assertExitCode(0);
    }

    public function testUnsupportedConnectionsFailBeforePurgingHorizonJobs(): void
    {
        $jobRepository = m::mock(JobRepository::class);
        $jobRepository->shouldNotReceive('purge');
        $this->app->instance(JobRepository::class, $jobRepository);

        $this->artisan('horizon:clear', ['connection' => 'sync', '--force' => true])
            ->expectsOutputToContain('Clearing queues is not supported on [SyncQueue]')
            ->assertExitCode(1);
    }
}
