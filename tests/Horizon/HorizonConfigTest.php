<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Horizon\Repositories\RedisJobRepository;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class HorizonConfigTest extends TestCase
{
    use VerifiesDoubles;

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
        $app = Double::for(Application::class, override: true)->passthru();
        $app->allows('configurationIsCached')->returns(false);
        $app->allows('make')->with('config')->returns($config);

        (new HorizonConfigServiceProvider($app->instance()))->register();

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
            $app = Double::for(Application::class, override: true)->passthru();
            $app->allows('configurationIsCached')->returns(false);
            $app->allows('make')->with('config')->returns($config);

            (new HorizonServiceProviderForTesting($app->instance()))->normalize();

            $this->assertSame($name === '0' ? '0' : 'Hypervel', $config->get('horizon.name'));
        }
    }

    public function testRedisConnectionUsesTheConfiguredHostAndPrefix(): void
    {
        $config = new ConfigRepository([
            'database' => ['redis' => ['default' => ['host' => 'redis.internal']]],
            'horizon' => ['prefix' => 'jobs:'],
        ]);
        $app = Double::for(Application::class, override: true)->passthru();
        $app->allows('configurationIsCached')->returns(false);
        $app->allows('make')->with('config')->returns($config);
        Container::setInstance($app->instance());

        (new HorizonServiceProviderForTesting($app->instance()))->configureForTest();

        $this->assertSame('redis.internal', $config->get('database.redis.horizon.host'));
        $this->assertSame('jobs:', $config->get('database.redis.horizon.prefix'));
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
