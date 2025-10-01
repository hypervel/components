<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Pool\PoolFactory;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\Contracts\TagRepository;
use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Http\Middleware\Authenticate;
use Hypervel\Horizon\SupervisorCommandString;
use Hypervel\Horizon\WorkerCommandString;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

abstract class IntegrationTest extends TestCase
{
    use RunTestsInCoroutine;

    const HORIZON_PREFIX = 'hypervel_test_horizon:';

    protected array $originalQueueConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadServiceProviders();

        $this->flushRedis();

        $this->beforeApplicationDestroyed(function () {
            /* $this->flushRedis(); */
            WorkerCommandString::reset();
            SupervisorCommandString::reset();
            Horizon::$authUsing = null;
        });
    }

    protected function tearDown(): void
    {
        $config = $this->app->get(ConfigInterface::class);
        $config->set('queue', $this->originalQueueConfig);

        $poolFactory = $this->app->get(PoolFactory::class);
        $pool = $poolFactory->getPool('default');
        $pool->flushOne(true);

        parent::tearDown();
    }

    public function setUpInCoroutine()
    {
        $poolFactory = $this->app->get(PoolFactory::class);

        defer(function () use ($poolFactory) {
            $pool = $poolFactory->getPool('default');
            $pool->flushOne(true);

            $pool = $poolFactory->getPool('horizon');
            $pool->flushOne(true);
        });
    }

    protected function loadServiceProviders(): void
    {
        $config = $this->app->get(ConfigInterface::class);
        $config->set('horizon.middleware', [Authenticate::class]);
        $config->set('horizon.prefix', static::HORIZON_PREFIX);

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

        $serviceProvider = new HorizonServiceProvider($this->app);
        $serviceProvider->register();
        $serviceProvider->boot();
    }

    protected function flushRedis(): void
    {
        Redis::eval(
            "return redis.call('del', unpack(redis.call('keys', ARGV[1])))",
            0,
            static::HORIZON_PREFIX . '*',
        );

        Redis::eval(
            "return redis.call('del', unpack(redis.call('keys', ARGV[1])))",
            0,
            config('database.redis.options.prefix', '') . '*',
        );
    }

    /**
     * Run the given assertion callback with a retry loop.
     */
    public function wait(Closure $callback): void
    {
        retry(10, $callback, 1000);
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
        return tap(new WorkerOptions(), function ($options) {
            $options->sleep = 0;
            $options->maxTries = 1;
        });
    }

    /**
     * Get the service providers for the package.
     */
    protected function getPackageProviders(Application $app): array
    {
        return ['Hypervel\Horizon\HorizonServiceProvider'];
    }

    /**
     * Configure the environment.
     */
    protected function getEnvironmentSetUp(Application $app): void
    {
        $app['config']->set('queue.default', 'redis');
    }
}
