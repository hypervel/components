<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Console;

use FilesystemIterator;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\Console\TestCommand;
use Hypervel\Testing\ParallelRunner;
use Hypervel\Testing\Profile\ProfileExtension;
use Override;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Tester\CommandTester;

use function Hypervel\Testbench\package_path;

class TestCommandTest extends TestCase
{
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
        $originalArguments = $_SERVER['argv'] ?? [];
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
            $_SERVER['argv'] = $originalArguments;
            $this->removeDirectory($basePath);
        }
    }

    #[Test]
    public function itShowsNativePhpunitOutputForSequentialTests(): void
    {
        $basePath = $this->createProfileProject();
        $originalArguments = $_SERVER['argv'] ?? [];
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
            $_SERVER['argv'] = $originalArguments;
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

final class TestCommandHarness extends TestCommand
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
