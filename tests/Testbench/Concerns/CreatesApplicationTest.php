<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Composer\Autoload\ClassLoader;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Bootstrap\LoadConfiguration as FoundationLoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\PackageManifest as FoundationPackageManifest;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Bootstrap\LoadConfiguration as TestbenchLoadConfiguration;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

#[WithConfig('database.default', 'testing')]
class CreatesApplicationTest extends TestCase
{
    protected array $registeredProviders = [];

    protected ?object $facadeRootDuringConfiguration = null;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TestServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     */
    protected function getPackageAliases(ApplicationContract $app): array
    {
        return [
            'TestAlias' => TestFacade::class,
            'ReplacedTestAlias' => TestFacade::class,
        ];
    }

    /**
     * Override application aliases.
     */
    protected function overrideApplicationAliases(ApplicationContract $app): array
    {
        return [
            'Benchmark' => false,
            'ReplacedTestAlias' => ReplacementTestFacade::class,
            'MissingTestAlias' => TestFacade::class,
        ];
    }

    /**
     * Override application bindings.
     */
    protected function overrideApplicationBindings(ApplicationContract $app): array
    {
        return [
            FoundationLoadConfiguration::class => RecordingLoadConfiguration::class,
            FoundationPackageManifest::class => OverriddenPackageManifest::class,
        ];
    }

    /**
     * Capture the facade root before configuration is loaded.
     */
    public function captureFacadeRootDuringConfiguration(ApplicationContract $app): void
    {
        $this->facadeRootDuringConfiguration = App::getFacadeRoot();
    }

    public function testRegisterPackageProvidersRegistersProviders(): void
    {
        // The package provider is registered during application configuration.
        $this->assertTrue(
            $this->app->providerIsLoaded(TestServiceProvider::class),
            'TestServiceProvider should be registered'
        );
    }

    public function testPackageProvidersBootWithTheTestingDatabaseResolver(): void
    {
        $this->assertInstanceOf(
            DatabaseConnectionResolver::class,
            $this->app->make('test.boot_database_resolver'),
        );
    }

    public function testResolveApplicationAliasesAppliesPackageAliasesAndOverrides(): void
    {
        $aliases = config()->array('app.aliases');

        $this->assertArrayHasKey('App', $aliases);
        $this->assertSame(TestFacade::class, $aliases['TestAlias']);
        $this->assertSame(ReplacementTestFacade::class, $aliases['ReplacedTestAlias']);
        $this->assertArrayNotHasKey('Benchmark', $aliases);
        $this->assertArrayNotHasKey('MissingTestAlias', $aliases);
        $this->assertSame(ReplacementTestFacade::class, (new ReflectionClass('ReplacedTestAlias'))->getName());
    }

    public function testApplicationBindingOverridesReplaceTheConfigurationLoader(): void
    {
        $this->assertSame(RecordingLoadConfiguration::class, $this->app->make('test.configuration_loader'));
    }

    public function testApplicationBindingOverridesReplaceThePackageManifest(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(ManifestTestServiceProvider::class));
    }

    #[ResolvesHypervel('captureFacadeRootDuringConfiguration')]
    public function testFacadesResolveTheApplicationBeforeConfigurationLoads(): void
    {
        $this->assertSame($this->app, $this->facadeRootDuringConfiguration);
    }

    #[WithConfig('rate-limiter.default', 'worker-array')]
    public function testPackageProvidersCanReplaceTheDefaultApiRateLimiter(): void
    {
        Route::middleware('throttle:api')->get('throttled', fn (): string => 'throttled');

        $this->get('throttled')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '5');
    }

    public function testDefaultApplicationRegistersBootstrapProviders(): void
    {
        $filesystem = new Filesystem;
        $path = $this->app->getBootstrapProvidersPath();
        $original = $filesystem->get($path);

        try {
            $filesystem->put($path, '<?php return [' . BootstrapFileTestServiceProvider::class . '::class];');

            $this->refreshApplication();

            $this->assertTrue($this->app->providerIsLoaded(BootstrapFileTestServiceProvider::class));
        } finally {
            $filesystem->put($path, $original);
        }
    }

    public function testDefaultApplicationDiscoversApplicationCommands(): void
    {
        $filesystem = new Filesystem;
        $directory = $this->app->path('Console/Commands');
        $directoryExisted = $filesystem->isDirectory($directory);
        $path = $directory . '/TestbenchDefaultApplicationCommand.php';
        $loader = new ClassLoader;
        $loader->addPsr4('App\Console\Commands\\', $directory);

        try {
            $filesystem->ensureDirectoryExists($directory);
            $filesystem->put($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Hypervel\Console\Command;

class TestbenchDefaultApplicationCommand extends Command
{
    protected ?string $signature = 'testbench:default-application-command';

    public function handle(): void
    {
        $this->line('discovered');
    }
}
PHP);
            $loader->register();

            $this->refreshApplication();

            $this->artisan('testbench:default-application-command')
                ->expectsOutput('discovered')
                ->assertSuccessful();
        } finally {
            $loader->unregister();
            $filesystem->delete($path);

            if (! $directoryExisted) {
                $filesystem->deleteDirectory($directory);
            }
        }
    }

    public function testAfterLoadingEnvironmentRegistersThroughTestbenchPath(): void
    {
        // The bootstrapped event should have been dispatched by bootstrapWith()
        // in CreatesApplication::resolveApplicationConfiguration().
        $events = $this->app->make(Dispatcher::class);
        $listeners = $events->getListeners(
            'bootstrapped: ' . LoadEnvironmentVariables::class
        );

        // Register a callback now and verify it gets added to the listener list.
        $this->app->afterLoadingEnvironment(static function (): void {
        });

        $updatedListeners = $events->getListeners(
            'bootstrapped: ' . LoadEnvironmentVariables::class
        );

        $this->assertCount(count($listeners) + 1, $updatedListeners);
    }

    public function testParallelCachePathSanitizesParaTestWorkerToken(): void
    {
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;
        $previousRoutesCache = $_SERVER['APP_ROUTES_CACHE'] ?? null;

        try {
            $_SERVER['TEST_TOKEN'] = 'worker/token:one';
            $_ENV['TEST_TOKEN'] = 'worker/token:one';

            $this->configureParallelCachePaths();

            $this->assertSame('cache/routes-v7-test-worker_token_one.php', $_SERVER['APP_ROUTES_CACHE']);
        } finally {
            if ($previousServerToken === null) {
                unset($_SERVER['TEST_TOKEN']);
            } else {
                $_SERVER['TEST_TOKEN'] = $previousServerToken;
            }

            if ($previousEnvironmentToken === null) {
                unset($_ENV['TEST_TOKEN']);
            } else {
                $_ENV['TEST_TOKEN'] = $previousEnvironmentToken;
            }

            if ($previousRoutesCache === null) {
                unset($_SERVER['APP_ROUTES_CACHE']);
            } else {
                $_SERVER['APP_ROUTES_CACHE'] = $previousRoutesCache;
            }
        }
    }
}

/**
 * Test service provider for testing.
 */
class TestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('test.service', fn () => 'test_value');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->instance('test.boot_database_resolver', $this->app->make('db.resolver'));
        $this->app->make('db')->selectOne('select 1');

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
    }
}

/**
 * Test facade for testing.
 */
class TestFacade
{
    // Empty facade class for testing
}

class ReplacementTestFacade
{
}

class RecordingLoadConfiguration extends TestbenchLoadConfiguration
{
    /**
     * Load the configuration and record which loader ran.
     */
    public function bootstrap(ApplicationContract $app): void
    {
        parent::bootstrap($app);

        $app->instance('test.configuration_loader', static::class);
    }
}

class OverriddenPackageManifest extends FoundationPackageManifest
{
    /**
     * Create a new package manifest instance.
     */
    public function __construct(ApplicationContract $app)
    {
        parent::__construct(new Filesystem, $app->basePath(), $app->getCachedPackagesPath());
    }

    /**
     * Get all of the service provider class names for all packages.
     */
    public function providers(): array
    {
        return [ManifestTestServiceProvider::class];
    }

    /**
     * Get all of the aliases for all packages.
     */
    public function aliases(): array
    {
        return [];
    }
}

class ManifestTestServiceProvider extends ServiceProvider
{
}

class BootstrapFileTestServiceProvider extends ServiceProvider
{
}
