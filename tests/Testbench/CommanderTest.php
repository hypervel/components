<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Concerns\Database\InteractsWithSqliteDatabaseFile;
use Hypervel\Testbench\Console\Commander;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Console\TerminatingConsole;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Testbench\Fixtures\Providers\MergedConfigServiceProvider;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\remote;
use function Hypervel\Testbench\uses_default_skeleton;

#[RequiresOperatingSystem('Linux|Darwin')]
class CommanderTest extends TestCase
{
    use InteractsWithSqliteDatabaseFile;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('CommanderTest');

        (new Filesystem)->deleteDirectory($this->tempDir);
        (new Filesystem)->makeDirectory($this->tempDir, 0700, recursive: true);
    }

    protected function tearDown(): void
    {
        TerminatingConsole::flush();
        CommanderLifecycleTestbench::reset();
        (new Filesystem)->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndGetCurrentVersion(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote(['--version', '--no-ansi']);
            $process->mustRun();

            $this->assertSame('Hypervel Framework ' . Application::VERSION . PHP_EOL, $process->getOutput());
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndGetCurrentEnvironment(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('env --no-ansi', ['APP_ENV' => 'workbench']);
            $process->mustRun();

            $this->assertSame('INFO  The application environment is [workbench].', trim($process->getOutput()));
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndDiscoverPackages(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('package:discover --no-ansi');
            $process->mustRun();

            $this->assertStringContainsString('INFO  Discovering packages.', $process->getOutput());
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliWithPackageProvidersThatMergeConfiguration(): void
    {
        $filesystem = new Filesystem;
        $filesystem->link(package_path('vendor'), $this->tempDir . '/vendor');
        $filesystem->put(
            $this->tempDir . '/testbench.yaml',
            "providers:\n  - " . MergedConfigServiceProvider::class . "\ndont-discover:\n  - hypervel/components\n",
        );

        $this->withoutSqliteDatabase(function (): void {
            $process = remote('config:show testbench.client --no-ansi', [
                'TESTBENCH_WORKING_PATH' => $this->tempDir,
            ]);
            $process->mustRun();

            $this->assertStringContainsString('rest', $process->getOutput());
        });
    }

    #[Test]
    public function itRunsCommandsOnTheConfiguredSkeletonWithoutCopyingIt(): void
    {
        $this->createPackage("hypervel: ./skeleton\n");
        $skeletonPath = $this->createSkeleton('skeleton');

        [$basePath, $applicationBasePath] = $this->remoteBasePaths();

        $this->assertSame($skeletonPath, $basePath);
        $this->assertSame($skeletonPath, $applicationBasePath);
    }

    #[Test]
    public function itPrefersAnExplicitApplicationBasePathOverTheConfiguredSkeleton(): void
    {
        $this->createPackage("hypervel: ./skeleton\n");
        $this->createSkeleton('skeleton');
        $explicitPath = $this->createSkeleton('explicit');

        [$basePath, $applicationBasePath] = $this->remoteBasePaths(['APP_BASE_PATH' => $explicitPath]);

        $this->assertSame($explicitPath, $basePath);
        $this->assertSame($explicitPath, $applicationBasePath);
    }

    #[Test]
    public function itRunsCommandsOnACopyOfTheDefaultSkeletonForTheTestbenchAlias(): void
    {
        $this->createPackage("hypervel: '@testbench'\n");

        // The child starts its own runtime instead of sharing this test's copy.
        [$basePath, $applicationBasePath, $usesDefaultSkeleton] = $this->remoteBasePaths(['TESTBENCH_BASE_PATH' => false]);

        $this->assertSame($basePath, $applicationBasePath);
        $this->assertNotSame(BASE_PATH, $basePath);
        $this->assertNotSame(realpath(package_path('src/testbench/hypervel')), $basePath);
        $this->assertTrue($usesDefaultSkeleton);
    }

    /**
     * Create a package in the temporary directory with the given Testbench configuration.
     */
    private function createPackage(string $configuration): void
    {
        $filesystem = new Filesystem;
        $filesystem->link(package_path('vendor'), $this->tempDir . '/vendor');
        $filesystem->put($this->tempDir . '/testbench.yaml', $configuration . "dont-discover:\n  - hypervel/components\n");
    }

    /**
     * Copy the default skeleton into the temporary package and return its path.
     */
    private function createSkeleton(string $directory): string
    {
        $path = $this->tempDir . '/' . $directory;

        $this->assertTrue((new Filesystem)->copyDirectory(package_path('src/testbench/hypervel'), $path));

        return (string) realpath($path);
    }

    /**
     * Get BASE_PATH, the application base path and whether the default skeleton is used in a remote command.
     *
     * @param array<string, false|string> $env
     * @return array{0: string, 1: string, 2: bool}
     */
    private function remoteBasePaths(array $env = []): array
    {
        $paths = [];

        $this->withoutSqliteDatabase(function () use ($env, &$paths): void {
            $process = remote(
                static fn (): array => [BASE_PATH, base_path(), uses_default_skeleton(base_path())],
                ['TESTBENCH_WORKING_PATH' => $this->tempDir, ...$env],
            );

            // A bootstrap that never finishes fails here instead of hanging the test.
            $process->setTimeout(20);

            $paths = $process->mustRun()->output();
        });

        return $paths;
    }

    #[Test]
    public function itOutputCorrectDefaults(): void
    {
        $this->withoutSqliteDatabase(function () {
            $process = remote('about --json');
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench', $output['environment']['application_name']);
            $this->assertSame(true, $output['environment']['debug_mode']);
            $this->assertSame('testing', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itOutputCorrectDefaultsWithDatabaseFile(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('about --json');
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench', $output['environment']['application_name']);
            $this->assertSame(true, $output['environment']['debug_mode']);
            $this->assertSame('sqlite', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itOutputCorrectDefaultsWithEnvironmentOverrides(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('about --json', [
                'APP_NAME' => 'Testbench Tests',
                'APP_DEBUG' => '(false)',
                'DB_CONNECTION' => 'testing',
            ]);
            $process->mustRun();

            $output = json_decode($process->getOutput(), true);

            $this->assertSame('Testbench Tests', $output['environment']['application_name']);
            $this->assertSame(false, $output['environment']['debug_mode']);
            $this->assertSame('testing', $output['drivers']['database']);
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndRunMigration(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('migrate', [
                'DB_CONNECTION' => 'sqlite',
            ]);
            $process->mustRun();

            $this->assertSame([
                '0001_01_01_000000_testbench_create_users_table',
                '0001_01_01_000001_testbench_create_password_reset_tokens_table',
                '0001_01_01_000002_testbench_create_sessions_table',
                '0001_01_01_000003_testbench_create_cache_table',
                '0001_01_01_000004_testbench_create_cache_locks_table',
                '0001_01_01_000005_testbench_create_job_batches_table',
                '0001_01_01_000006_testbench_create_jobs_table',
                '0001_01_01_000007_testbench_create_failed_jobs_table',
                '0001_01_01_000008_testbench_create_rate_limits_table',
                '2013_07_26_182750_create_testbench_users_table',
            ], DB::connection('sqlite')->table('migrations')->pluck('migration')->all());
        });
    }

    #[Test]
    public function itCanCallCommanderUsingCliAndRunMigrationWithoutDefaultMigration(): void
    {
        $this->withSqliteDatabase(function () {
            $process = remote('migrate', [
                'DB_CONNECTION' => 'sqlite',
                'TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS' => '(true)',
                'APP_MAINTENANCE_STORE' => 'array',
                'CACHE_STORE' => 'array',
            ]);
            $process->mustRun();

            $this->assertSame([
                '2013_07_26_182750_create_testbench_users_table',
            ], DB::connection('sqlite')->table('migrations')->pluck('migration')->all());
        });
    }

    #[Test]
    public function itRendersApplicationThrowablesToTheConsoleErrorOutput(): void
    {
        $exception = new RuntimeException('Application failure');
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($exception);
        $handler->expects('renderForConsole')->with($errorOutput, $exception);
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $commander = new CommanderThrowableHarness([], __DIR__);
        $commander->useApplication($this->app);

        $this->assertSame(1, $commander->renderThrowable($output, $exception));
    }

    #[Test]
    public function itRetainsApplicationThrowableWhenReportingFails(): void
    {
        $original = new RuntimeException('Application failure');
        $reportingFailure = new RuntimeException('Reporting failure');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('renderForConsole');
        $this->app->instance(ExceptionHandlerContract::class, $handler);

        $commander = new CommanderThrowableHarness([], __DIR__);
        $commander->useApplication($this->app);

        try {
            $commander->renderThrowable(new ConsoleOutput, $original);
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    #[Test]
    public function itRendersBootstrapThrowablesToTheConsoleErrorOutput(): void
    {
        $output = new ConsoleOutput;
        $errorOutput = new BufferedOutput;
        $output->setErrorOutput($errorOutput);

        $commander = new CommanderThrowableHarness([], __DIR__);

        $this->assertSame(1, $commander->renderThrowable($output, new RuntimeException('Bootstrap failure')));
        $this->assertStringContainsString('Bootstrap failure', $errorOutput->fetch());
    }

    #[Test]
    public function itDisposesTemporaryApplicationsAfterOwnershipTransfer(): void
    {
        $createdApplication = new Application($this->tempDir);
        $vendorApplication = new CommanderTrackingApplication;
        $deletionApplication = new CommanderTrackingApplication;
        CommanderLifecycleTestbench::$createdApplication = $createdApplication;
        CommanderLifecycleTestbench::$vendorApplication = $vendorApplication;
        CommanderLifecycleTestbench::$deletionApplication = $deletionApplication;

        $commander = new CommanderLifecycleHarness([], $this->tempDir, $this->tempDir);

        $this->assertSame($createdApplication, $commander->hypervel());
        $this->assertSame(['terminate', 'flush'], $vendorApplication->lifecycle);

        TerminatingConsole::handle();

        $this->assertSame(['terminate', 'flush'], $deletionApplication->lifecycle);
    }

    #[Test]
    public function itPreservesCopyFailureWhileExhaustingTemporaryApplicationCleanup(): void
    {
        $copyFailure = new RuntimeException('copy failed');
        $vendorApplication = new CommanderTrackingApplication(
            new RuntimeException('termination failed'),
            new RuntimeException('flush failed'),
        );
        CommanderLifecycleTestbench::$vendorApplication = $vendorApplication;

        $commander = new CommanderLifecycleHarness([], $this->tempDir, $this->tempDir);
        $commander->copyConfigurationFailure = $copyFailure;

        try {
            $commander->hypervel();
            $this->fail('Expected configuration copying to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($copyFailure, $exception);
        }

        $this->assertSame(['terminate', 'flush'], $vendorApplication->lifecycle);
    }

    #[Test]
    public function itRunsEveryCommanderCleanupPhaseAndPreservesTheFirstFailure(): void
    {
        $terminationFailure = new RuntimeException('termination callback failed');
        $signalFailure = new RuntimeException('signal cleanup failed');
        TerminatingConsole::before(static function () use ($terminationFailure): never {
            throw $terminationFailure;
        });
        Workbench::$cachedCoreBindings = ['kernel' => ['console' => 'changed'], 'handler' => []];

        $commander = new CommanderLifecycleHarness([], $this->tempDir, $this->tempDir);
        $commander->signalCleanupFailure = $signalFailure;

        $this->assertSame($terminationFailure, $commander->cleanUp());
        $this->assertTrue($commander->signalsWereUnregistered);
        $this->assertSame(['kernel' => [], 'handler' => []], Workbench::$cachedCoreBindings);
    }

    #[Test]
    public function itRendersCleanupFailuresAndReturnsTheirStatus(): void
    {
        $process = new Process(
            [
                php_binary(),
                package_path('tests/Testbench/Fixtures/CommanderCleanupFailure.php'),
                '--version',
                '--no-ansi',
            ],
            cwd: package_path(),
            env: [
                'COMMANDER_FIXTURE_MODE' => 'command',
                'TESTBENCH_WORKING_PATH' => package_path(),
            ],
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Command cleanup failed.', $process->getErrorOutput());
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function itReportsSignalCleanupFailuresWithoutReplacingTheSignalStatus(): void
    {
        $process = new Process(
            [php_binary(), package_path('tests/Testbench/Fixtures/CommanderCleanupFailure.php')],
            cwd: package_path(),
            env: [
                'COMMANDER_FIXTURE_MODE' => 'signal',
                'TESTBENCH_WORKING_PATH' => package_path(),
            ],
        );

        $process->run();

        $this->assertSame(143, $process->getExitCode());
        $this->assertStringContainsString('Signal ' . SIGTERM . ' cleanup failed.', $process->getErrorOutput());
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function itPreservesTheUpstreamSuccessfulSigintStatusAfterCleanupFailure(): void
    {
        $process = new Process(
            [php_binary(), package_path('tests/Testbench/Fixtures/CommanderCleanupFailure.php')],
            cwd: package_path(),
            env: [
                'COMMANDER_FIXTURE_MODE' => 'signal',
                'COMMANDER_FIXTURE_SIGNAL' => 'SIGINT',
                'TESTBENCH_WORKING_PATH' => package_path(),
            ],
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('Signal ' . SIGINT . ' cleanup failed.', $process->getErrorOutput());
    }
}

final class CommanderThrowableHarness extends Commander
{
    public function useApplication(ApplicationContract $app): void
    {
        $this->app = $app;
    }

    public function renderThrowable(OutputInterface $output, Throwable $throwable): int
    {
        return $this->handleException($output, $throwable);
    }
}

final class CommanderLifecycleHarness extends Commander
{
    protected const string TESTBENCH = CommanderLifecycleTestbench::class;

    public ?Throwable $copyConfigurationFailure = null;

    public ?Throwable $signalCleanupFailure = null;

    public bool $signalsWereUnregistered = false;

    public function __construct(array $config, string $workingPath, protected string $applicationBasePath)
    {
        parent::__construct($config, $workingPath);
    }

    public function cleanUp(): ?Throwable
    {
        $method = new ReflectionMethod(Commander::class, 'cleanUpCommand');

        /** @var null|Throwable */
        return $method->invoke($this);
    }

    protected function getApplicationBasePath(): string
    {
        return $this->applicationBasePath;
    }

    protected function copyTestbenchConfigurationFile(
        ApplicationContract $app,
        Filesystem $filesystem,
        string $workingPath,
        bool $backupExistingFile = true,
        bool $resetOnTerminating = true,
    ): void {
        if ($this->copyConfigurationFailure !== null) {
            throw $this->copyConfigurationFailure;
        }
    }

    protected function copyTestbenchDotEnvFile(
        ApplicationContract $app,
        Filesystem $filesystem,
        string $workingPath,
        bool $backupExistingFile = true,
        bool $resetOnTerminating = true,
    ): void {
    }

    protected function unregisterSignals(): void
    {
        $this->signalsWereUnregistered = true;

        if ($this->signalCleanupFailure !== null) {
            throw $this->signalCleanupFailure;
        }
    }
}

class CommanderLifecycleTestbench extends TestbenchApplication
{
    public static ?ApplicationContract $createdApplication = null;

    public static ?ApplicationContract $vendorApplication = null;

    public static ?ApplicationContract $deletionApplication = null;

    public static function create(
        ?string $basePath = null,
        ?callable $resolvingCallback = null,
        array $options = [],
    ): ApplicationContract {
        return static::$createdApplication ?? throw new RuntimeException('No created application configured.');
    }

    public static function createVendorSymlink(?string $basePath, string $workingVendorPath): ApplicationContract
    {
        return static::$vendorApplication ?? throw new RuntimeException('No vendor application configured.');
    }

    public static function deleteVendorSymlink(?string $basePath): ApplicationContract
    {
        return static::$deletionApplication ?? throw new RuntimeException('No deletion application configured.');
    }

    public static function reset(): void
    {
        static::$createdApplication = null;
        static::$vendorApplication = null;
        static::$deletionApplication = null;
    }
}

class CommanderTrackingApplication extends Application
{
    /** @var list<string> */
    public array $lifecycle = [];

    public function __construct(
        protected ?Throwable $terminationFailure = null,
        protected ?Throwable $flushFailure = null,
    ) {
        parent::__construct();
    }

    public function terminate(): void
    {
        $this->lifecycle[] = 'terminate';

        if ($this->terminationFailure !== null) {
            throw $this->terminationFailure;
        }
    }

    public function flush(): void
    {
        $this->lifecycle[] = 'flush';

        if ($this->flushFailure !== null) {
            throw $this->flushFailure;
        }
    }
}
