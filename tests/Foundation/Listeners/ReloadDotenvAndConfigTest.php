<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\ReloadsConfiguration;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Logger\StdoutLogger;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Configuration\ConfigMutationTracker;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Config as ConfigFacade;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class ReloadDotenvAndConfigTest extends TestCase
{
    protected ?string $originalAppName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppName = getenv('APP_NAME') ?: null;

        DotenvManager::flushState();
        Env::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::flushState();
        Env::flushState();
        $this->restoreAppName();

        parent::tearDown();
    }

    public function testReloadsUsingApplicationEnvironmentFile(): void
    {
        $app = $this->createApp();

        // Initial load with default .env.
        DotenvManager::load([$app->environmentPath()]);
        $this->assertSame('Hypervel', Env::get('APP_NAME'));

        // Switch to .env.testing (simulates LoadEnvironmentVariables having selected it).
        $app->loadEnvironmentFrom('.env.testing');

        $event = m::mock(BeforeWorkerStart::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $listener->handle($event);

        // After reload, values should come from .env.testing.
        $this->assertSame('HypervelTesting', Env::get('APP_NAME'));
        $this->assertSame('testing_value', Env::get('TEST_KEY'));
    }

    public function testMissingEnvironmentFileClearsPreviouslyLoadedValues(): void
    {
        $app = $this->createApp();
        $app->loadEnvironmentFrom('.env.nonexistent');

        // Initial load with default .env so there's something cached.
        DotenvManager::load([$app->environmentPath()]);
        $this->assertSame('Hypervel', Env::get('APP_NAME'));

        $event = m::mock(BeforeWorkerStart::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $listener->handle($event);

        $this->assertNull(Env::get('APP_NAME'));
    }

    public function testReloadPreservesRepositoryIdentityAndMutationsMadeBeforeListenerResolution(): void
    {
        $app = $this->createApp();
        $originalConfig = $app->make(Repository::class);

        $originalConfig->set('app.name', 'Reloaded Hypervel');

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $reloadedConfig = $app->make(Repository::class);

        $this->assertInstanceOf(Repository::class, $reloadedConfig);
        $this->assertNotInstanceOf(ConfigFacade::class, $reloadedConfig);
        $this->assertSame($originalConfig, $reloadedConfig);
        $this->assertSame('Reloaded Hypervel', $reloadedConfig->get('app.name'));
    }

    public function testReloadReplaysOverlappingMutationsInTheirOriginalOrder(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);

        $config->set('app', ['name' => 'First']);
        $config->set('app.name', 'Second');
        $config->set('app', ['name' => 'Last']);

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame(['name' => 'Last'], $config->get('app'));
    }

    public function testReloadPreservesUntouchedSiblingsWhenReplayingAChildMutation(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $environment = $config->get('app.env');

        $config->set('app.name', 'Reloaded Hypervel');

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame('Reloaded Hypervel', $config->get('app.name'));
        $this->assertSame($environment, $config->get('app.env'));
    }

    public function testTrackerSealsAfterReplayAndDoesNotRecordLaterMutations(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);

        $config->set('app.name', 'Boot Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $config->set('app.name', 'Post Start Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame('Boot Mutation', $config->get('app.name'));
    }

    public function testTrackerSealsWhenThereAreNoBootMutations(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $originalName = $config->get('app.name');

        $listener->handle(m::mock(BeforeWorkerStart::class));
        $config->set('app.name', 'Post Start Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame($originalName, $config->get('app.name'));
    }

    public function testReloadReevaluatesPackageConfigMergesAgainstWorkerEnvironment(): void
    {
        $app = new Application(__DIR__ . '/../Fixtures');
        $app->useEnvironmentPath(__DIR__ . '/../Fixtures/envs');
        (new LoadConfiguration)->bootstrap($app);
        DotenvManager::load([$app->environmentPath()]);
        $tempDirectory = ParallelTesting::tempDir('ReloadDotenvAndConfigTest-package');
        (new Filesystem)->deleteDirectory($tempDirectory);
        mkdir($tempDirectory, 0777, true);
        $packageConfigPath = $tempDirectory . '/package.php';

        try {
            file_put_contents($packageConfigPath, <<<'PHP'
<?php

return [
    'environment' => env('TEST_KEY'),
];
PHP);

            $provider = new class($app, $packageConfigPath) extends ServiceProvider {
                public function __construct(Application $app, protected string $packageConfigPath)
                {
                    parent::__construct($app);
                }

                public function register(): void
                {
                    $this->mergeConfigFrom($this->packageConfigPath, 'worker_package');
                    $this->mergeConfigFrom($this->packageConfigPath, 'custom');
                }
            };
            $provider->register();

            $config = $app->make(Repository::class);
            $this->assertSame('default_value', $config->get('worker_package.environment'));
            $this->assertSame('default_value', $config->get('custom.environment'));
            $this->assertSame('bar', $config->get('custom.foo'));

            $app->loadEnvironmentFrom('.env.testing');
            $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

            $this->assertSame('testing_value', $config->get('worker_package.environment'));
            $this->assertSame('testing_value', $config->get('custom.environment'));
            $this->assertSame('bar', $config->get('custom.foo'));
        } finally {
            (new Filesystem)->deleteDirectory($tempDirectory);
        }
    }

    public function testReloadReevaluatesDerivedPackageConfigurationAgainstWorkerEnvironment(): void
    {
        $app = $this->createApp();
        DotenvManager::load([$app->environmentPath()]);
        $config = $app->make(Repository::class);

        $app->make(ConfigMutationTracker::class)->applyAndRecord(
            $config,
            static function (Repository $config): void {
                $environment = (string) Env::get('TEST_KEY');

                $config->set([
                    'app.url' => "https://{$environment}.example.com",
                    'app.key' => "key-{$environment}",
                    'app.name' => "Application {$environment}",
                    'sentry.logs_channel_level' => "level-{$environment}",
                    'logging.channels.sentry' => [
                        'driver' => 'custom-sentry',
                        'environment' => $environment,
                    ],
                ]);
            },
        );

        (new ReloadDerivedFortifyConfiguration($app))->configureDerivedValues();
        (new ReloadDerivedSentryConfiguration($app))->configureDerivedValues();
        (new ReloadDerivedHorizonConfiguration($app))->configureDerivedValues();

        $this->assertSame('default_value.example.com', $config->get('passkeys.relying_party_id'));
        $this->assertSame(['https://default_value.example.com'], $config->get('passkeys.allowed_origins'));
        $this->assertSame('key-default_value', $config->get('passkeys.user_handle_secret'));
        $this->assertSame(1000, $config->get('passkeys.timeout'));
        $this->assertSame('Application default_value', $config->get('horizon.name'));
        $this->assertSame('level-default_value', $config->get('logging.channels.sentry_logs.level'));
        $this->assertSame('custom-sentry', $config->get('logging.channels.sentry.driver'));

        $app->loadEnvironmentFrom('.env.testing');
        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame('testing_value.example.com', $config->get('passkeys.relying_party_id'));
        $this->assertSame(['https://testing_value.example.com'], $config->get('passkeys.allowed_origins'));
        $this->assertSame('key-testing_value', $config->get('passkeys.user_handle_secret'));
        $this->assertSame(2000, $config->get('passkeys.timeout'));
        $this->assertSame('Application testing_value', $config->get('horizon.name'));
        $this->assertSame('level-testing_value', $config->get('logging.channels.sentry_logs.level'));
        $this->assertSame([
            'driver' => 'custom-sentry',
            'environment' => 'testing_value',
        ], $config->get('logging.channels.sentry'));
    }

    public function testReloadRunsConfigurationHooksInProviderOrderAfterMutationReplay(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $unrelatedServiceResolved = false;

        $app->make(ConfigMutationTracker::class)->applyAndRecord(
            $config,
            static function (Repository $config): void {
                $config->set('reload.calls', ['mutation']);
            },
        );
        $app->register(new FirstReloadConfigurationProvider($app));
        $app->register(new NonReloadConfigurationProvider($app));
        $app->register(new SecondReloadConfigurationProvider($app));
        $app->bind(ReloadUnrelatedService::class, function () use (&$unrelatedServiceResolved) {
            $unrelatedServiceResolved = true;

            return new ReloadUnrelatedService;
        });

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame(['mutation', 'first', 'second'], $config->get('reload.calls'));
        $this->assertFalse($unrelatedServiceResolved);
    }

    public function testReloadRefreshesTheRetainedStdoutLoggerAfterMutationReplay(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $config->set([
            'app.stdout_log.level' => [LogLevel::ERROR],
            'app.stdout_log.format' => 'line',
        ]);
        $output = new BufferedOutput;
        $logger = new StdoutLogger($config, $output);
        $app->instance(StdoutLoggerInterface::class, $logger);

        $app->make(ConfigMutationTracker::class)->applyAndRecord(
            $config,
            static function (Repository $config): void {
                $config->set([
                    'app.stdout_log.level' => [LogLevel::INFO],
                    'app.stdout_log.format' => 'json',
                ]);
            },
        );

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));
        $logger->info('Refreshed.');

        $entry = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Refreshed.', $entry['message']);
    }

    public function testReloadStopsAtTheFirstFailingConfigurationHook(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);

        $app->register(new FailingReloadConfigurationProvider($app));
        $app->register(new SkippedReloadConfigurationProvider($app));

        try {
            $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));
            $this->fail('Expected configuration refresh to stop at the failing provider.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Configuration refresh failed.', $exception->getMessage());
        }

        $this->assertSame(['failing'], $config->get('reload.calls'));
    }

    protected function createApp(): Application
    {
        $app = new Application(__DIR__ . '/../Fixtures/envs');

        (new LoadConfiguration)->bootstrap($app);

        return $app;
    }

    protected function restoreAppName(): void
    {
        if ($this->originalAppName === null) {
            putenv('APP_NAME');
            unset($_ENV['APP_NAME'], $_SERVER['APP_NAME']);

            return;
        }

        putenv("APP_NAME={$this->originalAppName}");
        $_ENV['APP_NAME'] = $this->originalAppName;
        $_SERVER['APP_NAME'] = $this->originalAppName;
    }
}

class ReloadDerivedFortifyConfiguration extends FortifyServiceProvider
{
    public function configureDerivedValues(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3) . '/src/fortify/config/fortify.php', 'fortify');
        $this->configurePasskeys();
    }
}

class ReloadDerivedSentryConfiguration extends SentryServiceProvider
{
    public function configureDerivedValues(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3) . '/src/sentry/config/sentry.php', 'sentry');
        $this->registerLogChannels();
    }
}

class ReloadDerivedHorizonConfiguration extends HorizonServiceProvider
{
    public function configureDerivedValues(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3) . '/src/horizon/config/horizon.php', 'horizon');
        $this->normalizeConfig();
    }
}

class FirstReloadConfigurationProvider extends ServiceProvider implements ReloadsConfiguration
{
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('reload.calls', [...$config->array('reload.calls', []), 'first']);
    }
}

class SecondReloadConfigurationProvider extends ServiceProvider implements ReloadsConfiguration
{
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('reload.calls', [...$config->array('reload.calls', []), 'second']);
    }
}

class FailingReloadConfigurationProvider extends ServiceProvider implements ReloadsConfiguration
{
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('reload.calls', [...$config->array('reload.calls', []), 'failing']);

        throw new RuntimeException('Configuration refresh failed.');
    }
}

class SkippedReloadConfigurationProvider extends ServiceProvider implements ReloadsConfiguration
{
    public function reloadConfiguration(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set('reload.calls', [...$config->array('reload.calls', []), 'skipped']);
    }
}

class NonReloadConfigurationProvider extends ServiceProvider
{
    public function reloadConfiguration(): never
    {
        throw new RuntimeException('Non-reload provider was invoked.');
    }
}

class ReloadUnrelatedService
{
}
