<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Http\Middleware\Authenticate;
use Hypervel\Horizon\SupervisorCommandString;
use Hypervel\Horizon\WorkerCommandString;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Testbench\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    use InteractsWithRedis;

    public const HORIZON_PREFIX = 'hypervel_test_horizon:';

    protected array $originalQueueConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function () {
            WorkerCommandString::flushState();
            SupervisorCommandString::flushState();
            Horizon::$authUsing = null;
        });
    }

    protected function tearDown(): void
    {
        $config = $this->app->make('config');
        $config->set('queue', $this->originalQueueConfig);

        $poolFactory = $this->app->make(PoolFactory::class);
        $pool = $poolFactory->getPool('default');
        $pool->flushOne(true);

        parent::tearDown();
    }

    public function setUpInCoroutine(): void
    {
        $poolFactory = $this->app->make(PoolFactory::class);

        Coroutine::defer(function () use ($poolFactory) {
            $pool = $poolFactory->getPool('default');
            $pool->flushOne(true);

            $pool = $poolFactory->getPool('horizon');
            $pool->flushOne(true);
        });
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    protected function resolveApplicationConfiguration(ApplicationContract $app): void
    {
        parent::resolveApplicationConfiguration($app);

        $this->configureHorizonEnvironment($app);
    }

    protected function configureHorizonEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        // Horizon snapshots these values in register(), before the Redis trait applies its normal worker DB.
        $config->set('database.redis.default.database', $this->getParallelRedisDb());
        $config->set('horizon.prefix', static::HORIZON_PREFIX);
        $config->set('horizon.middleware', [Authenticate::class]);

        $queueConfig = $this->originalQueueConfig = $config->get('queue', []);
        $queueConfig['default'] = 'redis';
        $queueConfig['connections']['redis'] = [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ];
        $config->set('queue', $queueConfig);
    }

    /**
     * Run the given assertion callback with a retry loop.
     */
    public function wait(Closure $callback): void
    {
        retry(200, $callback, 50);
    }

    /**
     * Get the total number of recent jobs.
     */
    protected function recentJobs(): int
    {
        return app(JobRepository::class)->totalRecent();
    }

    /**
     * Get the total number of monitored jobs for a given tag.
     */
    protected function monitoredJobs(string $tag): int
    {
        return app(TagRepository::class)->count($tag);
    }

    /**
     * Get the total number of failed jobs.
     */
    protected function failedJobs(): int
    {
        return app(JobRepository::class)->totalFailed();
    }

    /**
     * Run the next job on the queue.
     */
    protected function work(int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->worker()->runNextJob(
                'redis',
                'default',
                $this->workerOptions()
            );
        }
    }

    /**
     * Get the queue worker instance.
     */
    protected function worker(): Worker
    {
        return app('queue.worker');
    }

    /**
     * Get the options for the worker.
     */
    protected function workerOptions(): WorkerOptions
    {
        return tap(new WorkerOptions, function ($options) {
            $options->sleep = 0;
            $options->maxTries = 1;
        });
    }
}
