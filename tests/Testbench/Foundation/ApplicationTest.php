<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Http\Request;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\PHPUnit\TestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\BootstrapFileApplication;
use Hypervel\Tests\Testbench\Fixtures\Providers\FailingBootServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\default_skeleton_path;
use function Hypervel\Testbench\package_path;

class ApplicationTest extends TestCase
{
    protected string $customApplicationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customApplicationPath = ParallelTesting::tempDir('TestbenchFoundationApplicationTest');

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->customApplicationPath);

        if (! $filesystem->copyDirectory(
            dirname(__DIR__) . '/Fixtures/ApplicationWithBootstrap',
            $this->customApplicationPath,
        )) {
            throw new RuntimeException('Unable to create the custom application fixture.');
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->customApplicationPath);

        parent::tearDown();
    }

    #[Test]
    public function itCanCreateAnApplication(): void
    {
        $testbench = new TestbenchApplication((string) default_skeleton_path());
        $app = $testbench->createApplication();

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';
        $applicationEnvironment = $app->make('env');

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertSame($environment, $applicationEnvironment);
        $this->assertSame($applicationEnvironment, $app->make('config')->string('app.env'));
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
        $this->assertFalse($testbench->isRunningTestCase());
    }

    #[Test]
    public function itCanCreateAnApplicationUsingCreateHelper(): void
    {
        $app = TestbenchApplication::create((string) default_skeleton_path());

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';
        $applicationEnvironment = $app->make('env');

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertSame($environment, $applicationEnvironment);
        $this->assertSame($applicationEnvironment, $app->make('config')->string('app.env'));
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
    }

    #[Test]
    public function itCanCreateAnApplicationUsingCreateFromConfigHelper(): void
    {
        $config = new Config([
            'hypervel' => (string) default_skeleton_path(),
        ]);

        $app = TestbenchApplication::createFromConfig($config);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';
        $applicationEnvironment = $app->make('env');

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertSame($environment, $applicationEnvironment);
        $this->assertSame($applicationEnvironment, $app->make('config')->string('app.env'));
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
    }

    #[Test]
    public function itRunsTheResolvingCallbackBeforeTheApplicationBoots(): void
    {
        $bootingCallbackRan = false;

        $app = TestbenchApplication::create(
            (string) default_skeleton_path(),
            static function (ApplicationContract $app) use (&$bootingCallbackRan): void {
                $app->booting(static function () use (&$bootingCallbackRan): void {
                    $bootingCallbackRan = true;
                });
            },
        );

        try {
            $this->assertTrue($bootingCallbackRan);
        } finally {
            try {
                $app->terminate();
            } finally {
                $app->flush();
            }
        }
    }

    /**
     * @param array<int, string> $environment
     */
    #[Test]
    #[DataProvider('defaultMigrationEnvironments')]
    public function itReadsTheDefaultMigrationsSettingFromEnvironmentLoadedAfterTheResolvingCallback(
        array $environment,
        bool $includesDefaultMigrations,
    ): void {
        $app = TestbenchApplication::create(
            (string) default_skeleton_path(),
            static function (ApplicationContract $app): void {
                (new LoadMigrationsFromArray([]))->bootstrap($app);
            },
            ['extra' => ['env' => $environment]],
        );

        try {
            $this->assertSame(
                $includesDefaultMigrations,
                in_array(default_migration_path(), $app->make('migrator')->paths(), true),
            );
        } finally {
            Env::forget('TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS');

            try {
                $app->terminate();
            } finally {
                $app->flush();
            }
        }
    }

    /**
     * Get environment values and whether default migrations load with them.
     *
     * @return iterable<string, array{array<int, string>, bool}>
     */
    public static function defaultMigrationEnvironments(): iterable
    {
        yield 'default migrations' => [[], true];
        yield 'without default migrations' => [['TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS=(true)'], false];
    }

    /**
     * @param array<string, bool> $options
     * @param array<int, string> $expected
     */
    #[Test]
    #[DataProvider('packageDiscoveryOptions')]
    public function itAppliesThePackageDiscoveryOption(array $options, array $expected): void
    {
        $testbench = TestbenchApplication::make(options: [
            ...$options,
            'extra' => ['dont-discover' => ['vendor/package']],
        ]);

        $this->assertSame($expected, $testbench->ignorePackageDiscoveriesFrom());
    }

    /**
     * Get package discovery options and the packages they ignore.
     *
     * @return array<string, array{array<string, bool>, array<int, string>}>
     */
    public static function packageDiscoveryOptions(): array
    {
        return [
            'not set' => [[], ['vendor/package']],
            'enabled' => [['enables_package_discoveries' => true], []],
            'disabled' => [['enables_package_discoveries' => false], ['*']],
        ];
    }

    #[Test]
    public function itScopesBootstrapFileSelectionToEachApplicationObject(): void
    {
        $fixturesPath = dirname(__DIR__) . '/Fixtures';
        $withoutBootstrap = new BootstrapFileResolverTestbenchApplication($fixturesPath);
        $withoutBootstrapApplication = $withoutBootstrap->resolveApplicationForTest();

        try {
            $this->assertNotInstanceOf(BootstrapFileApplication::class, $withoutBootstrapApplication);

            $withBootstrap = new BootstrapFileResolverTestbenchApplication(
                "{$fixturesPath}/ApplicationWithBootstrap",
            );
            $withBootstrapApplication = $withBootstrap->resolveApplicationForTest();

            try {
                $this->assertInstanceOf(BootstrapFileApplication::class, $withBootstrapApplication);
                $this->assertSame(
                    realpath("{$fixturesPath}/ApplicationWithBootstrap/bootstrap/app.php"),
                    $withBootstrapApplication->bootstrapFile,
                );
            } finally {
                $withBootstrapApplication->flush();
            }
        } finally {
            $withoutBootstrapApplication->flush();
        }
    }

    #[Test]
    public function itPreservesCustomApplicationKernelsWithoutReplayingBootstrap(): void
    {
        $app = TestbenchApplication::create($this->customApplicationPath);

        try {
            $this->assertInstanceOf(BootstrapFileApplication::class, $app);
            $this->assertSame(
                realpath("{$this->customApplicationPath}/bootstrap/app.php"),
                $app->bootstrapFile,
            );
            $this->assertSame(HttpKernel::class, get_class($app->make(HttpKernelContract::class)));
            $this->assertSame(ConsoleKernel::class, get_class($app->make(ConsoleKernelContract::class)));
            $this->assertSame(0, $app->frameworkBootstrapCount);
            $this->assertTrue($app->hasBeenBootstrapped());
        } finally {
            try {
                $app->terminate();
            } finally {
                $app->flush();
            }
        }
    }

    #[Test]
    public function itPreservesTheCustomApplicationMiddlewareConfiguration(): void
    {
        $app = TestbenchApplication::create($this->customApplicationPath);

        try {
            $kernel = $app->make(HttpKernelContract::class);

            $this->assertArrayHasKey('fixture-alias', $kernel->getMiddlewareAliases());
            $this->assertContains('fixture-alias', $kernel->getMiddlewareGroups()['web']);
            $this->assertSame('/fixture-login', (new AuthenticationException)->redirectTo(Request::create('/')));
        } finally {
            try {
                $app->terminate();
            } finally {
                $app->flush();
            }
        }
    }

    #[Test]
    public function itTerminatesAndFlushesWhenTheResolvingCallbackFails(): void
    {
        $originalTimezone = date_default_timezone_get();
        $resolvingFailure = new RuntimeException('resolving failed');
        $terminationFailure = new RuntimeException('termination failed');
        $testbench = new FailingResolvingTestbenchApplication(
            (string) default_skeleton_path(),
            static function () use ($resolvingFailure): never {
                throw $resolvingFailure;
            },
        );
        $testbench->withTerminationFailure($terminationFailure);

        try {
            date_default_timezone_set('Europe/London');

            try {
                $testbench->createApplication();
                $this->fail('Expected the resolving callback to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame($resolvingFailure, $exception);
            }

            $this->assertSame('Europe/London', date_default_timezone_get());
            $this->assertSame(
                ['terminate', 'flush'],
                $testbench->createdApplication?->lifecycle,
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[Test]
    public function itRestoresExistingHandlersWhenBootFails(): void
    {
        $failure = new RuntimeException('boot failed');
        $errorHandler = static fn (): bool => false;
        $exceptionHandler = static function (Throwable $exception): void {};

        FailingBootServiceProvider::$exception = $failure;
        set_error_handler($errorHandler);
        set_exception_handler($exceptionHandler);

        try {
            try {
                TestbenchApplication::create(
                    (string) default_skeleton_path(),
                    options: ['extra' => ['providers' => [FailingBootServiceProvider::class]]],
                );
                $this->fail('Expected the application to fail while booting.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertSame($errorHandler, get_error_handler());
            $this->assertSame($exceptionHandler, get_exception_handler());
        } finally {
            FailingBootServiceProvider::$exception = null;
            restore_exception_handler();
            restore_error_handler();
        }
    }

    /**
     * @param list<string> $iniSettings
     */
    #[Test]
    #[DataProvider('errorReportingSettings')]
    public function itReportsAnUncaughtBootFailureWithoutTheFlushedApplication(array $iniSettings): void
    {
        $process = new Process(
            [php_binary(), ...$iniSettings, package_path('tests/Testbench/Fixtures/failing-standalone-boot.php')],
            cwd: package_path(),
        );

        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertSame(255, $process->getExitCode());
        $this->assertSame(1, substr_count($output, 'The failing boot fixture failed.'));
        $this->assertStringNotContainsString('BindingResolutionException', $output);
    }

    /**
     * Get PHP settings that report errors through the log or the display.
     *
     * @return array<string, array{list<string>}>
     */
    public static function errorReportingSettings(): array
    {
        return [
            'logged' => [['-d', 'log_errors=1', '-d', 'error_log=', '-d', 'display_errors=0']],
            'displayed' => [['-d', 'log_errors=0', '-d', 'display_errors=stderr']],
        ];
    }

    #[Test]
    public function itRestoresTheTimezoneWhenVendorApplicationOwnershipDoesNotTransfer(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/London');

            try {
                StandaloneTimezoneTestbenchApplication::createVendorSymlink(
                    (string) default_skeleton_path(),
                    (string) default_skeleton_path('missing-vendor'),
                );
                $this->fail('Expected vendor symlink creation to fail.');
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame('Europe/London', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}

class FailingResolvingTestbenchApplication extends TestbenchApplication
{
    public ?ResolvingTrackingApplication $createdApplication = null;

    protected ?Throwable $terminationFailure = null;

    public function withTerminationFailure(Throwable $terminationFailure): void
    {
        $this->terminationFailure = $terminationFailure;
    }

    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }

    protected function resolveApplication(): ApplicationContract
    {
        return $this->createdApplication = new ResolvingTrackingApplication(
            $this->getApplicationBasePath(),
            $this->terminationFailure,
        );
    }
}

class BootstrapFileResolverTestbenchApplication extends TestbenchApplication
{
    /**
     * Resolve the application without booting it.
     */
    public function resolveApplicationForTest(): ApplicationContract
    {
        return $this->resolveApplication();
    }
}

class ResolvingTrackingApplication extends Application
{
    /** @var list<string> */
    public array $lifecycle = [];

    public function __construct(?string $basePath, protected ?Throwable $terminationFailure)
    {
        parent::__construct($basePath);
    }

    public function terminate(): void
    {
        $this->lifecycle[] = 'terminate';

        parent::terminate();

        if ($this->terminationFailure !== null) {
            throw $this->terminationFailure;
        }
    }

    public function flush(): void
    {
        $this->lifecycle[] = 'flush';

        parent::flush();
    }
}

class StandaloneTimezoneTestbenchApplication extends TestbenchApplication
{
    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }
}
