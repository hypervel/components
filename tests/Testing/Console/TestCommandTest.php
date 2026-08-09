<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Console;

use FilesystemIterator;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Console\TestCommand;
use Hypervel\Testing\Coverage;
use Hypervel\Testing\ParallelRunner;
use Hypervel\Testing\Profile\ProfileExtension;
use Override;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function Hypervel\Testbench\package_path;

class TestCommandTest extends TestCase
{
    /** @var array<string, array{bool, null|string}> */
    private array $originalConfigurationFiles = [];

    /** @var array{bool, mixed} */
    private array $originalArguments;

    protected function setUp(): void
    {
        $this->originalArguments = [
            array_key_exists('argv', $_SERVER),
            $_SERVER['argv'] ?? null,
        ];

        parent::setUp();

        foreach (['phpunit.xml', 'custom-phpunit.xml'] as $file) {
            $path = $this->app->basePath($file);
            $this->originalConfigurationFiles[$path] = [
                is_file($path),
                is_file($path) ? (string) file_get_contents($path) : null,
            ];
        }
    }

    protected function tearDown(): void
    {
        $exception = null;

        try {
            $profileFiles = glob($this->app->basePath('.hypervel-phpunit-profile-*.xml')) ?: [];
        } catch (Throwable $throwable) {
            $exception = $throwable;
            $profileFiles = [];
        }

        foreach ($profileFiles as $path) {
            try {
                unlink($path);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        foreach ($this->originalConfigurationFiles as $path => [$existed, $contents]) {
            try {
                if ($existed) {
                    file_put_contents($path, $contents);
                } elseif (is_file($path)) {
                    unlink($path);
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($this->originalArguments[0]) {
            $_SERVER['argv'] = $this->originalArguments[1];
        } else {
            unset($_SERVER['argv']);
        }

        try {
            parent::tearDown();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    #[Test]
    public function itInjectsTheProfileExtensionIntoATemporaryConfigurationFile(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness(['profile' => true]);
        $command->setHypervel($this->app);

        $arguments = $command->phpunitArgumentsPublic([]);

        $configurationArgument = $this->firstConfigurationArgument($arguments);
        $configurationFile = substr($configurationArgument, strlen('--configuration='));

        $this->assertNotSame($this->app->basePath('phpunit.xml'), $configurationFile);
        $this->assertSame(dirname($this->app->basePath('phpunit.xml')), dirname($configurationFile));
        $this->assertFileExists($configurationFile);
        $this->assertStringContainsString(ProfileExtension::class, (string) file_get_contents($configurationFile));

        $command->cleanupTemporaryConfigurationFilePublic();

        $this->assertFileDoesNotExist($configurationFile);

        $nextArguments = $command->phpunitArgumentsPublic([]);
        $nextConfigurationArgument = $this->firstConfigurationArgument($nextArguments);
        $nextConfigurationFile = substr($nextConfigurationArgument, strlen('--configuration='));

        $this->assertNotSame($configurationFile, $nextConfigurationFile);
        $this->assertFileExists($nextConfigurationFile);
    }

    #[Test]
    public function itRunsProfiledTestsWithRelativeConfigurationPaths(): void
    {
        $basePath = $this->createProfileProject();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandHarness(['profile' => true, 'without-tty' => true], $basePath);
        $command->setHypervel($this->app);
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
            $display = $tester->getDisplay();

            $this->assertSame(0, $exitCode, $display);
            $this->assertStringContainsString('Top 10 slowest tests', $display);
            $this->assertStringContainsString('ProfileExampleTest', $display);
        } finally {
            $this->removeDirectory($basePath);
        }
    }

    #[Test]
    public function itShowsNativePhpunitOutputForSequentialTests(): void
    {
        $basePath = $this->createProfileProject();
        $_SERVER['argv'] = ['artisan', 'test'];

        $command = new TestCommandHarness(['without-tty' => true], $basePath);
        $command->setHypervel($this->app);
        $tester = new CommandTester($command);

        try {
            $exitCode = $tester->execute([]);
            $display = $tester->getDisplay();

            $this->assertSame(0, $exitCode, $display);
            $this->assertStringContainsString('OK (1 test', $display);
            $this->assertStringContainsString('1 assertion', $display);
        } finally {
            $this->removeDirectory($basePath);
        }
    }

    #[Test]
    public function itDoesNotRewriteAConfigurationThatAlreadyRegistersTheProfileExtension(): void
    {
        $this->writePhpunitConfiguration(
            '<extensions><bootstrap class="' . ProfileExtension::class . '"/></extensions>'
        );

        $command = new TestCommandHarness(['profile' => true]);
        $command->setHypervel($this->app);

        $this->assertSame($this->app->basePath('phpunit.xml'), $command->phpUnitConfigurationFilePublic());
    }

    #[Test]
    public function itBuildsParatestArgumentsAndEnvironmentVariablesForApplicationTests(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness([
            'parallel' => true,
            'recreate-databases' => true,
            'drop-databases' => true,
            'without-databases' => true,
            'without-cache' => true,
            'profile' => true,
        ]);
        $command->setHypervel($this->app);

        $arguments = $command->paratestArgumentsPublic([
            '--parallel',
            '--drop-databases',
            '--without-cache',
            '--profile',
            '--env=ci',
            '--filter=Example',
        ]);
        $variables = $command->paratestEnvironmentVariablesPublic();

        $this->assertContains('--runner=' . ParallelRunner::class, $arguments);
        $this->assertContains('--filter=Example', $arguments);
        $this->assertNotContains('--profile', $arguments);
        $this->assertNotContains('--env=ci', $arguments);
        $this->assertSame(1, $variables['HYPERVEL_PARALLEL_TESTING']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_DROP_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE']);
        $this->assertSame('1', $variables[TestCommand::PROFILE_ENV]);
        $this->assertIsString($variables[TestCommand::PROFILE_DIRECTORY_ENV]);
    }

    #[Test]
    public function itFiltersParallelOnlyOptionsFromPhpunitArguments(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness;
        $command->setHypervel($this->app);

        $arguments = $command->phpunitArgumentsPublic([
            '--parallel',
            '--drop-databases',
            '--without-cache',
            '--filter=Example',
        ]);

        $this->assertContains('--filter=Example', $arguments);
        $this->assertNotContains('--parallel', $arguments);
        $this->assertNotContains('--drop-databases', $arguments);
        $this->assertNotContains('--without-cache', $arguments);
    }

    #[Test]
    public function itUsesTheRequestedEnvironmentForChildProcesses(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness(['env' => 'ci']);
        $command->setHypervel($this->app);

        $phpunitArguments = $command->phpunitArgumentsPublic(['--env=ci', '--filter=Example']);
        $paratestArguments = $command->paratestArgumentsPublic(['--env=ci', '--filter=Example']);

        $this->assertSame('ci', $command->phpunitEnvironmentVariablesPublic()['APP_ENV']);
        $this->assertSame('ci', $command->paratestEnvironmentVariablesPublic()['APP_ENV']);
        $this->assertContains('--filter=Example', $phpunitArguments);
        $this->assertContains('--filter=Example', $paratestArguments);
        $this->assertNotContains('--env=ci', $phpunitArguments);
        $this->assertNotContains('--env=ci', $paratestArguments);
    }

    #[Test]
    public function itClearsConfiguredEnvironmentVariablesFromEveryEnvironmentStore(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypervel-test-command-env-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
        $environmentFile = '.env.command-clear';
        $keys = [
            'HYPERVEL_TEST_COMMAND_CLEAR_ONE',
            'HYPERVEL_TEST_COMMAND_CLEAR_TWO',
        ];

        mkdir($basePath, 0777, true);
        file_put_contents($basePath . DIRECTORY_SEPARATOR . $environmentFile, implode("\n", [
            'HYPERVEL_TEST_COMMAND_CLEAR_ONE=one',
            'HYPERVEL_TEST_COMMAND_CLEAR_TWO=two',
        ]));

        $originalEnvironmentPath = $this->app->environmentPath();
        $originalEnvironmentFile = $this->app->environmentFile();

        foreach ($keys as $key) {
            $_SERVER[$key] = 'server';
            $_ENV[$key] = 'env';
            putenv("{$key}=process");
        }

        Env::enablePutenv();

        $command = new TestCommandHarness;
        $command->setHypervel($this->app);

        try {
            $this->app->useEnvironmentPath($basePath);
            $this->app->loadEnvironmentFrom($environmentFile);

            $command->clearEnvPublic();

            foreach ($keys as $key) {
                $this->assertArrayNotHasKey($key, $_SERVER);
                $this->assertArrayNotHasKey($key, $_ENV);
                $this->assertFalse(getenv($key));
            }
        } finally {
            if (is_string($originalEnvironmentPath)) {
                $this->app->useEnvironmentPath($originalEnvironmentPath);
            }

            $this->app->loadEnvironmentFrom($originalEnvironmentFile);

            foreach ($keys as $key) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            }

            Env::flushRepository();
            $this->removeDirectory($basePath);
        }
    }

    #[Test]
    public function itParsesTheConfiguredParatestConfigurationFile(): void
    {
        file_put_contents($this->app->basePath('phpunit.xml'), <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);
        $this->writePhpunitConfiguration(file: 'custom-phpunit.xml');

        $command = new TestCommandHarness(['configuration' => 'custom-phpunit.xml'], commonArguments: []);
        $command->setHypervel($this->app);

        $arguments = $command->paratestArgumentsPublic(['--configuration=custom-phpunit.xml']);

        $this->assertContains('--cache-directory', $arguments);
    }

    #[Test]
    public function itFiltersWithoutTtyFromForwardedArguments(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness;
        $command->setHypervel($this->app);

        $arguments = $command->phpunitArgumentsPublic(['--filter=Example', '--without-tty']);

        $this->assertContains('--filter=Example', $arguments);
        $this->assertNotContains('--without-tty', $arguments);
    }

    #[Test]
    public function itFiltersSpaceSeparatedCommandOptionValuesFromForwardedArguments(): void
    {
        $this->writePhpunitConfiguration();

        $command = new TestCommandHarness;
        $command->setHypervel($this->app);

        $arguments = $command->phpunitArgumentsPublic(['--min', '80', '--filter=Example']);

        $this->assertContains('--filter=Example', $arguments);
        $this->assertNotContains('--min', $arguments);
        $this->assertNotContains('80', $arguments);
    }

    #[Test]
    public function itRejectsAnUnpublishedTemporaryConfigurationFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks are unreliable when running as root.');
        }

        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypervel-profile-config-'
            . getmypid() . '-' . bin2hex(random_bytes(6));

        mkdir($basePath, 0700, true);
        file_put_contents($basePath . DIRECTORY_SEPARATOR . 'phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php"/>
XML);
        chmod($basePath, 0555);

        $command = new TestCommandHarness(['profile' => true], $basePath);
        $command->setHypervel($this->app);

        try {
            $command->phpUnitConfigurationFilePublic();
            $this->fail('The temporary configuration write failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                "Unable to write temporary PHPUnit configuration [{$basePath}/.hypervel-phpunit-profile-",
                $exception->getMessage(),
            );
        } finally {
            chmod($basePath, 0700);
            $this->removeDirectory($basePath);
        }
    }

    #[Test]
    public function itCleansEveryOwnedResourceWhenArgumentConstructionFails(): void
    {
        $this->writePhpunitConfiguration();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandFailureHarness(['profile' => true, 'without-tty' => true]);
        $command->phpunitArgumentsException = new RuntimeException('arguments failed');
        $command->setHypervel($this->app);

        try {
            (new CommandTester($command))->execute([]);
            $this->fail('The argument construction exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('arguments failed', $exception->getMessage());
        }

        $this->assertCommandResourcesWereRemoved($command);
        $this->assertSame(['temporary configuration', 'profile directory'], $command->cleanupOrder);
    }

    #[Test]
    public function itCleansEveryOwnedResourceAfterANonInterruptSignal(): void
    {
        $this->writePhpunitConfiguration();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandFailureHarness(['profile' => true, 'without-tty' => true]);
        $command->processCode = 'posix_kill(getmypid(), SIGTERM);';
        $command->setHypervel($this->app);

        try {
            (new CommandTester($command))->execute([]);
            $this->fail('The process signal exception was not thrown.');
        } catch (Throwable $throwable) {
            $this->assertSame('The process has been signaled with signal "15".', $throwable->getMessage());
        }

        $this->assertCommandResourcesWereRemoved($command);
        $this->assertSame(['temporary configuration', 'profile directory'], $command->cleanupOrder);
    }

    #[Test]
    public function itCleansEveryOwnedResourceWhenProfileReportingFails(): void
    {
        $this->writePhpunitConfiguration();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandFailureHarness(['profile' => true, 'without-tty' => true]);
        $command->profileReportException = new RuntimeException('report failed');
        $command->setHypervel($this->app);

        try {
            (new CommandTester($command))->execute([]);
            $this->fail('The profile report exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('report failed', $exception->getMessage());
        }

        $this->assertCommandResourcesWereRemoved($command);
        $this->assertSame(['temporary configuration', 'profile directory'], $command->cleanupOrder);
    }

    #[Test]
    public function itPreservesTheOperationFailureWhileExhaustingCleanup(): void
    {
        $this->writePhpunitConfiguration();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandFailureHarness(['profile' => true, 'without-tty' => true]);
        $command->allocateCoverageInBinary = true;
        $command->profileReportException = new RuntimeException('operation failed');
        $command->temporaryConfigurationCleanupException = new RuntimeException('temporary cleanup failed');
        $command->coverageCleanupException = new RuntimeException('coverage cleanup failed');
        $command->profileCleanupException = new RuntimeException('profile cleanup failed');
        $command->setHypervel($this->app);

        try {
            (new CommandTester($command))->execute([]);
            $this->fail('The operation exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('operation failed', $exception->getMessage());
        }

        $this->assertCommandResourcesWereRemoved($command);
        $this->assertSame(
            ['temporary configuration', 'coverage', 'profile directory'],
            $command->cleanupOrder,
        );
    }

    #[Test]
    public function itThrowsTheFirstCleanupFailureAndStillRunsLaterCleanup(): void
    {
        $this->writePhpunitConfiguration();
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandFailureHarness(['profile' => true, 'without-tty' => true]);
        $command->allocateCoverageInBinary = true;
        $command->temporaryConfigurationCleanupException = new RuntimeException('temporary cleanup failed');
        $command->coverageCleanupException = new RuntimeException('coverage cleanup failed');
        $command->profileCleanupException = new RuntimeException('profile cleanup failed');
        $command->setHypervel($this->app);

        try {
            (new CommandTester($command))->execute([]);
            $this->fail('The cleanup exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary cleanup failed', $exception->getMessage());
        }

        $this->assertCommandResourcesWereRemoved($command);
        $this->assertSame(
            ['temporary configuration', 'coverage', 'profile directory'],
            $command->cleanupOrder,
        );
    }

    /**
     * Assert that every command-owned filesystem resource was removed.
     */
    protected function assertCommandResourcesWereRemoved(TestCommandFailureHarness $command): void
    {
        $this->assertNotNull($command->createdTemporaryConfigurationFile);
        $this->assertFileDoesNotExist($command->createdTemporaryConfigurationFile);
        $this->assertNotNull($command->createdProfileDirectory);
        $this->assertDirectoryDoesNotExist($command->createdProfileDirectory);

        if ($command->coverageReporter !== null) {
            $this->assertFileDoesNotExist($command->coverageReporter->path());
        }
    }

    /**
     * Write a PHPUnit configuration file into the disposable testbench app.
     */
    protected function writePhpunitConfiguration(string $extensions = '', string $file = 'phpunit.xml'): void
    {
        file_put_contents($this->app->basePath($file), <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    {$extensions}
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);
    }

    /**
     * Get the configuration argument from a command argument list.
     *
     * @param array<int, string> $arguments
     */
    protected function firstConfigurationArgument(array $arguments): string
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--configuration=')) {
                return $argument;
            }
        }

        $this->fail('No configuration argument was found.');
    }

    /**
     * Create a tiny PHPUnit project with relative configuration paths.
     */
    protected function createProfileProject(): string
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hypervel-profile-project-'
            . getmypid() . '-' . bin2hex(random_bytes(6));

        mkdir($basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature', 0777, true);
        symlink(package_path('vendor'), $basePath . DIRECTORY_SEPARATOR . 'vendor');

        file_put_contents($basePath . DIRECTORY_SEPARATOR . 'phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);

        file_put_contents(
            $basePath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'ProfileExampleTest.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProfileExampleTest extends TestCase
{
    public function test_profile_runs(): void
    {
        usleep(1000);

        $this->assertTrue(true);
    }
}
PHP
        );

        return $basePath;
    }

    /**
     * Remove a temporary directory.
     */
    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isLink() || $file->isFile()) {
                unlink($file->getPathname());

                continue;
            }

            rmdir($file->getPathname());
        }

        rmdir($path);
    }
}

class TestCommandHarness extends TestCommand
{
    /**
     * Create a new test command harness.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?string $basePath = null,
        private readonly ?array $commonArguments = null,
    ) {
        parent::__construct();
    }

    /**
     * Get a command option.
     */
    #[Override]
    public function option(?string $key = null): array|bool|float|int|string|null
    {
        if ($key === null) {
            return $this->options;
        }

        return $this->options[$key] ?? false;
    }

    /**
     * Determine if Pest is being used.
     */
    #[Override]
    protected function usingPest(): bool
    {
        return false;
    }

    /**
     * Get the common arguments of PHPUnit and Pest.
     *
     * @return array<int, string>
     */
    #[Override]
    protected function commonArguments(): array
    {
        return $this->commonArguments ?? parent::commonArguments();
    }

    /**
     * Get an absolute path relative to the test project root.
     */
    #[Override]
    protected function basePath(string ...$paths): string
    {
        if ($this->basePath === null) {
            return parent::basePath(...$paths);
        }

        return $this->basePath . ($paths === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $paths));
    }

    /**
     * Expose the resolved PHPUnit configuration file.
     */
    public function phpUnitConfigurationFilePublic(): string
    {
        return $this->phpUnitConfigurationFile();
    }

    /**
     * Expose PHPUnit arguments.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    public function phpunitArgumentsPublic(array $options): array
    {
        return $this->phpunitArguments($options);
    }

    /**
     * Expose ParaTest arguments.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    public function paratestArgumentsPublic(array $options): array
    {
        return $this->paratestArguments($options);
    }

    /**
     * Expose PHPUnit environment variables.
     *
     * @return array<string, null|bool|int|string>
     */
    public function phpunitEnvironmentVariablesPublic(): array
    {
        return $this->phpunitEnvironmentVariables();
    }

    /**
     * Expose ParaTest environment variables.
     *
     * @return array<string, null|bool|int|string>
     */
    public function paratestEnvironmentVariablesPublic(): array
    {
        return $this->paratestEnvironmentVariables();
    }

    /**
     * Expose temporary configuration cleanup.
     */
    public function cleanupTemporaryConfigurationFilePublic(): void
    {
        $this->cleanupTemporaryConfigurationFile();
    }

    /**
     * Expose environment cleanup.
     */
    public function clearEnvPublic(): void
    {
        $this->clearEnv();
    }
}

class TestCommandFailureHarness extends TestCommandHarness
{
    public bool $allocateCoverageInBinary = false;

    public string $processCode = 'exit(0);';

    public ?Throwable $phpunitArgumentsException = null;

    public ?Throwable $profileReportException = null;

    public ?Throwable $temporaryConfigurationCleanupException = null;

    public ?Throwable $coverageCleanupException = null;

    public ?Throwable $profileCleanupException = null;

    public ?string $createdTemporaryConfigurationFile = null;

    public ?string $createdProfileDirectory = null;

    public ?TestCommandFailureCoverage $coverageReporter = null;

    /** @var list<string> */
    public array $cleanupOrder = [];

    /**
     * Get the PHP binary to execute.
     *
     * @return array<int, string>
     */
    #[Override]
    protected function binary(): array
    {
        if ($this->allocateCoverageInBinary) {
            $this->coverage();
        }

        return [PHP_BINARY, '-r', $this->processCode, '--'];
    }

    /**
     * Get the array of arguments for running PHPUnit.
     *
     * @param array<int, string> $options
     * @return array<int, string>
     */
    #[Override]
    protected function phpunitArguments(array $options): array
    {
        $arguments = parent::phpunitArguments($options);

        if ($this->phpunitArgumentsException !== null) {
            throw $this->phpunitArgumentsException;
        }

        return $arguments;
    }

    /**
     * Add the profile extension to a temporary PHPUnit configuration file.
     */
    #[Override]
    protected function profileConfigurationFile(string $file): string
    {
        return $this->createdTemporaryConfigurationFile = parent::profileConfigurationFile($file);
    }

    /**
     * Get the profile directory.
     */
    #[Override]
    protected function profileDirectory(): string
    {
        return $this->createdProfileDirectory = parent::profileDirectory();
    }

    /**
     * Get the coverage reporter.
     */
    #[Override]
    protected function coverage(): Coverage
    {
        return $this->coverage ??= $this->coverageReporter ??= new TestCommandFailureCoverage($this);
    }

    /**
     * Report the slowest tests.
     */
    #[Override]
    protected function reportProfile(): void
    {
        if ($this->profileReportException !== null) {
            throw $this->profileReportException;
        }
    }

    /**
     * Remove the temporary PHPUnit configuration file.
     */
    #[Override]
    protected function cleanupTemporaryConfigurationFile(): void
    {
        $this->cleanupOrder[] = 'temporary configuration';
        parent::cleanupTemporaryConfigurationFile();

        if ($this->temporaryConfigurationCleanupException !== null) {
            throw $this->temporaryConfigurationCleanupException;
        }
    }

    /**
     * Remove profile data.
     */
    #[Override]
    protected function cleanupProfileDirectory(): void
    {
        $this->cleanupOrder[] = 'profile directory';
        parent::cleanupProfileDirectory();

        if ($this->profileCleanupException !== null) {
            throw $this->profileCleanupException;
        }
    }
}

class TestCommandFailureCoverage extends Coverage
{
    /**
     * Create a coverage cleanup harness.
     */
    public function __construct(private readonly TestCommandFailureHarness $command)
    {
        parent::__construct();
    }

    /**
     * Remove temporary coverage data.
     */
    #[Override]
    public function cleanup(): void
    {
        $this->command->cleanupOrder[] = 'coverage';
        parent::cleanup();

        if ($this->command->coverageCleanupException !== null) {
            throw $this->command->coverageCleanupException;
        }
    }
}
