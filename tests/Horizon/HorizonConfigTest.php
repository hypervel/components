<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

class HorizonConfigTest extends TestCase
{
    public function testCanonicalDefaultsAreDeclared(): void
    {
        $config = $this->withEnvironmentValues(
            ['HORIZON_PATH' => null, 'HORIZON_ENV' => null],
            fn (): array => require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php',
        );

        $this->assertSame('', $config['proxy_path']);
        $this->assertSame('horizon', $config['path']);
        $this->assertSame('default', $config['use']);
        $this->assertSame(['web'], $config['middleware']);
        $this->assertSame([
            'recent' => RedisJobRepository::DEFAULT_RECENT_JOB_RETENTION,
            'pending' => 60,
            'completed' => 60,
            'recent_failed' => RedisJobRepository::DEFAULT_FAILED_JOB_RETENTION,
            'failed' => RedisJobRepository::DEFAULT_FAILED_JOB_RETENTION,
            'monitored' => RedisJobRepository::DEFAULT_MONITORED_JOB_RETENTION,
        ], $config['trim']);
        $this->assertSame([
            'job' => 24,
            'queue' => 24,
        ], $config['metrics']['trim_snapshots']);
        $this->assertSame(300, $config['metrics']['snapshot_lock']);
        $this->assertFalse($config['fast_termination']);
        $this->assertSame(64, $config['memory_limit']);
        $this->assertNull($config['env']);
    }

    public function testApplicationMetricsConfigurationReplacesPackageDefaults(): void
    {
        $config = new ConfigRepository([
            'horizon' => [
                'metrics' => [
                    'trim_snapshots' => ['job' => 12, 'queue' => 12],
                ],
            ],
        ]);
        $app = m::mock(Application::class)->makePartial();
        $app->shouldReceive('configurationIsCached')->andReturnFalse();
        $app->shouldReceive('make')->with('config')->andReturn($config);
        $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn(new ConfigMutationTracker);

        (new HorizonConfigServiceProvider($app))->register();

        $this->assertSame([
            'trim_snapshots' => ['job' => 12, 'queue' => 12],
        ], $config->get('horizon.metrics'));
    }

    public function testOnlyMissingAndBlankNamesUseTheApplicationName(): void
    {
        foreach ([null, '', '0'] as $name) {
            $config = new ConfigRepository([
                'app' => ['name' => 'Hypervel'],
                'horizon' => ['name' => $name],
            ]);
            $app = m::mock(Application::class)->makePartial();
            $app->shouldReceive('configurationIsCached')->andReturnFalse();
            $app->shouldReceive('make')->with('config')->andReturn($config);
            $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn(new ConfigMutationTracker);

            (new HorizonServiceProviderForTesting($app))->normalize();

            $this->assertSame($name === '0' ? '0' : 'Hypervel', $config->get('horizon.name'));
        }
    }

    public function testApplicationNameFallbackIsComputedAgainAgainstTheRebuiltConfiguration(): void
    {
        $masterConfig = new ConfigRepository([
            'app' => ['name' => 'Old Name'],
            'horizon' => ['name' => null],
        ]);
        $tracker = new ConfigMutationTracker;
        $tracker->observe($masterConfig);
        $app = m::mock(Application::class)->makePartial();
        $app->shouldReceive('configurationIsCached')->andReturnFalse();
        $app->shouldReceive('make')->with('config')->andReturn($masterConfig);
        $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn($tracker);

        (new HorizonServiceProviderForTesting($app))->normalize();

        $this->assertSame('Old Name', $masterConfig->get('horizon.name'));

        // A worker whose environment renamed the application uses the new name, unless Horizon's own name is now set.
        $workerConfig = new ConfigRepository([
            'app' => ['name' => 'New Name'],
            'horizon' => ['name' => null],
        ]);
        $tracker->replay($workerConfig);

        $this->assertSame('New Name', $workerConfig->get('horizon.name'));

        $namedWorkerConfig = new ConfigRepository([
            'app' => ['name' => 'New Name'],
            'horizon' => ['name' => 'Queues'],
        ]);
        $tracker->replay($namedWorkerConfig);

        $this->assertSame('Queues', $namedWorkerConfig->get('horizon.name'));
    }

    public function testRedisConnectionIsComputedAgainAgainstTheRebuiltConfiguration(): void
    {
        $masterConfig = new ConfigRepository([
            'database' => ['redis' => ['default' => ['host' => 'old-host']]],
            'horizon' => ['prefix' => 'old_horizon:'],
        ]);
        $tracker = new ConfigMutationTracker;
        $tracker->observe($masterConfig);
        // Horizon::use() reads the application's configuration, which a rebuild refreshes in place.
        $currentConfig = $masterConfig;
        $app = m::mock(Application::class)->makePartial();
        $app->shouldReceive('configurationIsCached')->andReturnFalse();
        $app->shouldReceive('make')->with('config')->andReturnUsing(static function () use (&$currentConfig): ConfigRepository {
            return $currentConfig;
        });
        $app->shouldReceive('make')->with(ConfigMutationTracker::class)->andReturn($tracker);
        Container::setInstance($app);

        (new HorizonServiceProviderForTesting($app))->configureForTest();

        $this->assertSame('old-host', $masterConfig->get('database.redis.horizon.host'));
        $this->assertSame('old_horizon:', $masterConfig->get('database.redis.horizon.prefix'));

        // A worker whose environment moved Redis and changed the prefix connects Horizon with the new settings.
        $currentConfig = $workerConfig = new ConfigRepository([
            'database' => ['redis' => ['default' => ['host' => 'new-host']]],
            'horizon' => ['prefix' => 'new_horizon:'],
        ]);
        $tracker->replay($workerConfig);

        $this->assertSame('new-host', $workerConfig->get('database.redis.horizon.host'));
        $this->assertSame('new_horizon:', $workerConfig->get('database.redis.horizon.prefix'));
        $this->assertSame('new_horizon:', $workerConfig->get('horizon.prefix'));
    }

    public function testMissingAndBlankPrefixUseApplicationScopedDefault(): void
    {
        foreach ([null, ''] as $prefix) {
            $config = $this->withEnvironmentValue(
                'HORIZON_PREFIX',
                $prefix,
                fn (): array => require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php',
            );

            $this->assertSame(app_id() . '_horizon:', $config['prefix']);
        }
    }
}

class HorizonConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 2) . '/src/horizon/config/horizon.php',
            'horizon',
        );
    }
}

class HorizonServiceProviderForTesting extends HorizonServiceProvider
{
    /**
     * Normalize the Horizon configuration.
     */
    public function normalize(): void
    {
        $this->normalizeConfig();
    }

    /**
     * Configure Horizon for the test.
     */
    public function configureForTest(): void
    {
        $this->configure();
    }
}
