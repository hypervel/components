<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Closure;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class LoadConfigurationTest extends TestCase
{
    public function testLoadsBaseConfiguration()
    {
        $app = new Application();

        (new LoadConfiguration())->bootstrap($app);

        $this->assertSame('Hypervel', $app['config']['app.name']);
    }

    public function testSetsEnvironmentResolver()
    {
        $app = new Application();
        $this->assertNull((new ReflectionClass($app))->getProperty('environmentResolver')->getValue($app));

        (new LoadConfiguration())->bootstrap($app);

        $this->assertInstanceOf(
            Closure::class,
            (new ReflectionClass($app))->getProperty('environmentResolver')->getValue($app)
        );
    }

    public function testDontLoadBaseConfiguration()
    {
        $app = new Application();
        $app->dontMergeFrameworkConfiguration();

        (new LoadConfiguration())->bootstrap($app);

        $this->assertNull($app['config']['app.name']);
    }

    public function testLoadsConfigurationInIsolation()
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');

        (new LoadConfiguration())->bootstrap($app);

        $this->assertNull($app['config']['bar.foo']);
        $this->assertSame('bar', $app['config']['custom.foo']);
    }

    public function testConfigurationArrayKeysMatchLoadedFilenames()
    {
        $baseConfigPath = dirname((new ReflectionClass(LoadConfiguration::class))->getFileName(), 3) . '/config';
        $customConfigPath = __DIR__ . '/../Fixtures/config';

        $app = new Application();
        $app->useConfigPath($customConfigPath);

        (new LoadConfiguration())->bootstrap($app);

        $this->assertEqualsCanonicalizing(
            array_keys($app['config']->all()),
            collect((new Filesystem())->files([
                $baseConfigPath,
                $customConfigPath,
            ]))->map(fn ($file) => $file->getBaseName('.php'))->unique()->values()->toArray()
        );
    }

    public function testShouldMergeFrameworkConfigurationDefaultsToTrue()
    {
        $app = new Application();

        $this->assertTrue($app->shouldMergeFrameworkConfiguration());
    }

    public function testDontMergeFrameworkConfigurationReturnsSelf()
    {
        $app = new Application();

        $result = $app->dontMergeFrameworkConfiguration();

        $this->assertSame($app, $result);
        $this->assertFalse($app->shouldMergeFrameworkConfiguration());
    }

    public function testBaseConfigurationIncludesCoreFrameworkConfigs()
    {
        $app = new Application();

        (new LoadConfiguration())->bootstrap($app);

        // All centralized framework configs should be loaded
        foreach (['app', 'auth', 'cache', 'database', 'logging', 'session', 'view'] as $key) {
            $this->assertNotNull(
                $app['config'][$key],
                "Framework config '{$key}' should be loaded by LoadConfiguration."
            );
        }
    }

    public function testDontMergeFrameworkConfigurationSkipsAllBaseConfigs()
    {
        $app = new Application();
        $app->dontMergeFrameworkConfiguration();

        (new LoadConfiguration())->bootstrap($app);

        // No base config should be present (app has no config dir with files)
        $this->assertNull($app['config']['auth']);
        $this->assertNull($app['config']['cache']);
        $this->assertNull($app['config']['database']);
    }

    public function testAppConfigOverridesBaseConfigValues()
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');

        (new LoadConfiguration())->bootstrap($app);

        // custom.php is app-specific, should be loaded
        $this->assertSame('bar', $app['config']['custom.foo']);

        // Base configs should still be loaded for keys not in the app config dir
        $this->assertNotNull($app['config']['auth']);
    }
}
