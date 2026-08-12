<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Tests\Testbench\Fixtures\MergeSeedersTestStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class WithWorkbenchTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itCanBeResolved(): void
    {
        $cachedConfig = Workbench::configuration();

        $this->assertInstanceOf(ConfigContract::class, $cachedConfig);

        $this->assertSame($cachedConfig, static::cachedConfigurationForWorkbench());

        $this->assertSame([
            'env' => ['APP_NAME="Testbench"'],
            'bootstrappers' => [],
            'providers' => ['Workbench\App\Providers\WorkbenchServiceProvider'],
            'dont-discover' => ['hypervel/components'],
        ], $cachedConfig->getExtraAttributes());
    }

    #[Test]
    public function itCanBeManuallyResolved(): void
    {
        $cachedConfig = static::cachedConfigurationForWorkbench();

        Workbench::flush();

        $config = static::cachedConfigurationForWorkbench();

        $this->assertInstanceOf(ConfigContract::class, $config);

        $this->assertSame($cachedConfig->toArray(), $config->toArray());
    }

    #[Test]
    public function itCanFlushCachedCoreBindings(): void
    {
        $reflection = new ReflectionClass(Workbench::class);
        $reflection->setStaticPropertyValue('cachedCoreBindings', [
            'kernel' => ['console' => 'Workbench\App\Console\Kernel'],
            'handler' => ['exception' => 'Workbench\App\Exceptions\Handler'],
        ]);

        Workbench::flushCachedClassAndNamespaces();

        $this->assertSame([
            'kernel' => [],
            'handler' => [],
        ], $reflection->getStaticPropertyValue('cachedCoreBindings'));
    }

    #[Test]
    public function itCanAutoDetectPackagesViaBootstrapProvidersFile(): void
    {
        $loadedProviders = collect($this->app->getLoadedProviders())->keys()->all();

        $this->assertContains('Workbench\App\Providers\AppServiceProvider', $loadedProviders);
    }

    #[Test]
    public function itRegistersTheRouteProviderAndWorkbenchRoutesOnce(): void
    {
        $this->assertCount(1, $this->app->getProviders(RouteServiceProvider::class));

        $routes = $this->app->make(Router::class)->getRoutes()->getRoutes();
        $testbenchRoutes = array_filter(
            $routes,
            static fn ($route): bool => $route->uri() === 'testbench'
        );

        $this->assertCount(1, $testbenchRoutes);
    }

    #[Test]
    public function itIgnoresStrayTestbenchAppBasePathEnvironmentValues(): void
    {
        $previousAppBasePathExists = array_key_exists('APP_BASE_PATH', $_ENV);
        $previousAppBasePath = $_ENV['APP_BASE_PATH'] ?? null;
        $previousTestbenchAppBasePathExists = array_key_exists('TESTBENCH_APP_BASE_PATH', $_ENV);
        $previousTestbenchAppBasePath = $_ENV['TESTBENCH_APP_BASE_PATH'] ?? null;

        try {
            unset($_ENV['APP_BASE_PATH']);
            $_ENV['TESTBENCH_APP_BASE_PATH'] = '/tmp/parent-runtime-clone';

            $this->assertNull(static::applicationBasePathUsingWorkbench());

            $_ENV['APP_BASE_PATH'] = '/tmp/user-override';

            $this->assertSame('/tmp/user-override', static::applicationBasePathUsingWorkbench());
        } finally {
            if ($previousAppBasePathExists) {
                $_ENV['APP_BASE_PATH'] = $previousAppBasePath;
            } else {
                unset($_ENV['APP_BASE_PATH']);
            }

            if ($previousTestbenchAppBasePathExists) {
                $_ENV['TESTBENCH_APP_BASE_PATH'] = $previousTestbenchAppBasePath;
            } else {
                unset($_ENV['TESTBENCH_APP_BASE_PATH']);
            }
        }
    }

    #[Test]
    public function itCanResolveUserModelFromWorkbench(): void
    {
        $this->assertFalse(Env::has('AUTH_MODEL'));
        $this->assertSame('Workbench\App\Models\User', config('auth.providers.users.model'));
    }

    #[Test]
    #[DataProvider('seedersDataProvider')]
    public function itCanMergeSeedersWithHypervelDatabaseRefresh(
        bool $seed,
        string|false $seeder,
        array|false $workbenchSeeders,
        array|false $expected
    ): void {
        $stub = new MergeSeedersTestStub($seed, $seeder);

        $config = new Config(['seeders' => $workbenchSeeders]);

        $this->assertSame($expected, $stub($config));
    }

    public static function seedersDataProvider(): iterable
    {
        yield [false, false, ['Workbench\Database\Seeders\DatabaseSeeder'], false];
        yield [true, false, ['Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [true, 'Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [false, 'Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], false];
        yield [true, 'Database\Seeders\DatabaseSeeder', ['Database\Seeders\DatabaseSeeder', 'Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [true, 'Workbench\Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], false];
    }
}
