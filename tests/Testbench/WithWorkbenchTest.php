<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Support\Providers\RouteServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\MergeSeedersTestStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;

class WithWorkbenchTest extends TestCase
{
    use WithWorkbench;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = ParallelTesting::tempDir('WithWorkbenchTest');

        (new Filesystem)->deleteDirectory($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

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
    public function itDiscoversNamespacesFromTheActiveWorkbenchPathAndRefreshesOnDemand(): void
    {
        $reflection = new ReflectionClass(Workbench::class);
        $reflection->setStaticPropertyValue('cachedNamespaces', ['app' => 'Cached\App\\']);

        $this->assertSame('Cached\App\\', Workbench::detectNamespace('app'));
        $this->assertSame('Workbench\App\\', Workbench::detectNamespace('app', true));
    }

    #[Test]
    public function itMemoizesMissingNamespacesAndCoreBindings(): void
    {
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($this->temporaryDirectory . '/workbench/app/Console', 0700, true);
        $filesystem->put($this->temporaryDirectory . '/composer.json', json_encode([
            'autoload-dev' => [
                'psr-4' => [
                    'Fixture\App\\' => 'workbench/app/',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $updatedComposer = json_encode([
            'autoload-dev' => [
                'psr-4' => [
                    'Fixture\App\\' => 'workbench/app/',
                    'Changed\\' => 'workbench/missing/',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $process = new Process(
            command: [
                php_binary(),
                '-r',
                sprintf(
                    <<<'PHP'
require %s;
$firstNamespace = \Hypervel\Testbench\Workbench\Workbench::detectNamespace('missing');
$firstKernel = \Hypervel\Testbench\Workbench\Workbench::applicationConsoleKernel();
file_put_contents(%s, %s);
touch(%s);
$secondNamespace = \Hypervel\Testbench\Workbench\Workbench::detectNamespace('missing');
$secondKernel = \Hypervel\Testbench\Workbench\Workbench::applicationConsoleKernel();
echo json_encode([$firstNamespace, $secondNamespace, $firstKernel, $secondKernel], JSON_THROW_ON_ERROR);
PHP,
                    var_export(package_path('vendor/autoload.php'), true),
                    var_export($this->temporaryDirectory . '/composer.json', true),
                    var_export($updatedComposer, true),
                    var_export($this->temporaryDirectory . '/workbench/app/Console/Kernel.php', true),
                ),
            ],
            cwd: $this->temporaryDirectory,
            env: ['TESTBENCH_WORKING_PATH' => $this->temporaryDirectory],
        );

        $process->mustRun();

        $this->assertSame(
            [null, null, null, null],
            json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR),
        );
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
    public function itPrefersAnExplicitUserModel(): void
    {
        try {
            Env::set('AUTH_MODEL', 'App\Models\CustomUser');
            Workbench::flushCachedClassAndNamespaces();

            $this->assertSame('App\Models\CustomUser', Workbench::applicationUserModel());
        } finally {
            Env::forget('AUTH_MODEL');
            Workbench::flushCachedClassAndNamespaces();
        }
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
