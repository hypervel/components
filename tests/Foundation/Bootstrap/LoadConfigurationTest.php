<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

class LoadConfigurationTest extends TestCase
{
    public function testLoadsBaseConfiguration(): void
    {
        $app = new Application;

        (new LoadConfiguration)->bootstrap($app);

        $this->assertSame('Hypervel', $app->make('config')->string('app.name'));
    }

    public function testResolvesTheFrameworkConfigurationDirectory(): void
    {
        $this->assertSame(
            realpath(dirname(__DIR__, 3) . '/src/foundation/config'),
            LoadConfiguration::frameworkConfigPath(),
        );
    }

    public function testSetsEnvironmentResolver(): void
    {
        $app = new Application;
        $this->assertNull((new ReflectionClass($app))->getProperty('environmentResolver')->getValue($app));

        (new LoadConfiguration)->bootstrap($app);

        $this->assertInstanceOf(
            Closure::class,
            (new ReflectionClass($app))->getProperty('environmentResolver')->getValue($app)
        );
    }

    public function testLoadsConfigurationInIsolation(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');

        (new LoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        $this->assertNull($config->get('bar.foo'));
        $this->assertSame('bar', $config->string('custom.foo'));
    }

    public function testConfigurationArrayKeysMatchLoadedFilenames(): void
    {
        $baseConfigPath = LoadConfiguration::frameworkConfigPath();
        $customConfigPath = __DIR__ . '/../Fixtures/config';

        $app = new Application;
        $app->useConfigPath($customConfigPath);

        (new LoadConfiguration)->bootstrap($app);

        $this->assertEqualsCanonicalizing(
            array_keys($app->make('config')->all()),
            collect((new Filesystem)->files([
                $baseConfigPath,
                $customConfigPath,
            ]))->map(fn ($file) => $file->getBaseName('.php'))->unique()->values()->toArray()
        );
    }

    public function testShouldMergeFrameworkConfigurationDefaultsToTrue(): void
    {
        $app = new Application;

        $this->assertTrue($app->shouldMergeFrameworkConfiguration());
    }

    public function testDontMergeFrameworkConfigurationReturnsSelf(): void
    {
        $app = new Application;

        $result = $app->dontMergeFrameworkConfiguration();

        $this->assertSame($app, $result);
        $this->assertFalse($app->shouldMergeFrameworkConfiguration());
    }

    public function testBaseConfigurationIncludesCoreFrameworkConfigs(): void
    {
        $app = new Application;

        (new LoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        // All centralized framework configs should be loaded
        foreach (['app', 'auth', 'cache', 'database', 'logging', 'session', 'view'] as $key) {
            $this->assertNotNull(
                $config->get($key),
                "Framework config '{$key}' should be loaded by LoadConfiguration."
            );
        }
    }

    public function testDontMergeFrameworkConfigurationSkipsAllBaseConfigs(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');
        $app->dontMergeFrameworkConfiguration();

        (new LoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        $this->assertSame('bar', $config->string('app.foo'));
        $this->assertSame('overwrite', $config->string('cache.default'));
        $this->assertSame('overwrite', $config->string('database.default'));
        $this->assertNull($config->get('app.name'));
        $this->assertNull($config->get('auth'));
        $this->assertNull($config->get('session'));
        $this->assertNull($config->get('view'));
    }

    public function testDontMergeFrameworkConfigurationRequiresApplicationEnvironment(): void
    {
        $app = new Application;
        $app->dontMergeFrameworkConfiguration();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [app.env] must be a string, NULL given.');

        (new LoadConfiguration)->bootstrap($app);
    }

    public function testCachedConfigurationRequiresApplicationTimezone(): void
    {
        LoadConfiguration::alwaysUse(fn (): array => [
            'app' => ['env' => 'testing'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [app.timezone] must be a string, NULL given.');

        (new LoadConfiguration)->bootstrap(new Application);
    }

    public function testAppConfigOverridesBaseConfigValues(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useConfigPath(__DIR__ . '/../Fixtures/config');

        (new LoadConfiguration)->bootstrap($app);

        $config = $app->make('config');

        // custom.php is app-specific, should be loaded
        $this->assertSame('bar', $config->string('custom.foo'));

        // Base configs should still be loaded for keys not in the app config dir
        $this->assertNotNull($config->get('auth'));
    }

    public function testFailedReloadRestoresThePreviousRepositoryAndException(): void
    {
        $app = new Application;
        (new LoadConfiguration)->bootstrap($app);

        $originalConfig = $app->make(Repository::class);
        $originalConfig->set('app.name', 'Original Hypervel');
        $exception = new RuntimeException('Configuration failed.');

        try {
            (new FailingLoadConfiguration($exception))->bootstrap($app);

            $this->fail('The configuration bootstrap did not fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame($originalConfig, $app->make(Repository::class));
        $this->assertSame('Original Hypervel', $originalConfig->get('app.name'));
    }
}

class FailingLoadConfiguration extends LoadConfiguration
{
    public function __construct(protected RuntimeException $exception)
    {
    }

    protected function loadConfigurationFiles(ApplicationContract $app, RepositoryContract $repository): void
    {
        throw $this->exception;
    }
}
