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
use Hypervel\Tests\Integration\Horizon\Feature\Fixtures\Jobs\BasicJob;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ClearCommandTest extends IntegrationTestCase
{
    use VerifiesDoubles;

    /**
     * Configure separate and shared queue storage connections.
     */
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

        $jobRepository = Double::for(RedisJobRepository::class)->strict();
        $jobRepository->expects('purge')->with($expectedQueue, $expectedConnection);
        $this->app->instance(JobRepository::class, $jobRepository);

        $resolvedQueue = Double::for(RedisQueue::class)->strict();
        $resolvedQueue->expects('getQueue')->with($expectedQueue)->returns('queues:' . $expectedQueue);
        $resolvedQueue->expects('getConnectionName')->returns($expectedConnection);
        $resolvedQueue->expects('clear')->with($expectedQueue)->returns(1);

        $manager = Double::for(QueueManager::class)->strict();
        $manager->expects('connection')->with($expectedConnection)->returns($resolvedQueue);
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
        $jobRepository = Double::for(JobRepository::class)->strict();
        $jobRepository->expects('purge')->never();
        $this->app->instance(JobRepository::class, $jobRepository);

        $resolvedQueue = Double::for(QueueContract::class, ClearableQueue::class)->strict();
        $resolvedQueue->expects('clear')->with('default')->returns(1);

        $manager = Double::for(QueueManager::class)->strict();
        $manager->expects('connection')->with('redis')->returns($resolvedQueue);
        $this->app->instance('queue', $manager);

        $this->artisan('horizon:clear', ['connection' => 'redis', '--force' => true])
            ->assertExitCode(0);
    }

    public function testUnsupportedConnectionsFailBeforePurgingHorizonJobs(): void
    {
        $jobRepository = Double::for(JobRepository::class)->strict();
        $jobRepository->expects('purge')->never();
        $this->app->instance(JobRepository::class, $jobRepository);

        $this->artisan('horizon:clear', ['connection' => 'sync', '--force' => true])
            ->expectsOutputToContain('Clearing queues is not supported on [SyncQueue]')
            ->assertExitCode(1);
    }
}
