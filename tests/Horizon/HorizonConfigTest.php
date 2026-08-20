<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Support\Env;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;

class HorizonConfigTest extends TestCase
{
    public function testCanonicalDefaultsAreDeclared(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php';

        $this->assertSame('', $config['proxy_path']);
        $this->assertSame(300, $config['metrics']['snapshot_lock']);
        $this->assertSame('horizon', $config['path']);
        $this->assertSame('default', $config['use']);
        $this->assertSame(['web'], $config['middleware']);
        $this->assertFalse($config['fast_termination']);
        $this->assertSame(64, $config['memory_limit']);
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
            $app->shouldReceive('make')->with('config')->andReturn($config);

            (new HorizonServiceProviderForTesting($app))->normalize();

            $this->assertSame($name === '0' ? '0' : 'Hypervel', $config->get('horizon.name'));
        }
    }

    public function testMissingAndBlankPrefixUseApplicationScopedDefault(): void
    {
        $key = 'HORIZON_PREFIX';
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php';

            $this->assertSame(app_id() . '_horizon:', $config['prefix']);

            putenv("{$key}=");
            $_SERVER[$key] = '';
            $_ENV[$key] = '';
            Env::flushRepository();

            $config = require dirname(__DIR__, 2) . '/src/horizon/config/horizon.php';

            $this->assertSame(app_id() . '_horizon:', $config['prefix']);
        } finally {
            $originalPutenv === false
                ? putenv($key)
                : putenv("{$key}={$originalPutenv}");

            if ($originalServerExists) {
                $_SERVER[$key] = $originalServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($originalEnvExists) {
                $_ENV[$key] = $originalEnv;
            } else {
                unset($_ENV[$key]);
            }

            Env::flushRepository();
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
    public function normalize(): void
    {
        $this->normalizeConfig();
    }
}
