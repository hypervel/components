<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Console;

use FilesystemIterator;
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
    }

    #[Test]
    public function itRunsProfiledTestsWithRelativeConfigurationPaths(): void
    {
        $basePath = $this->createProfileProject();
        $originalArguments = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'test', '--profile'];

        $command = new TestCommandHarness(['profile' => true], $basePath);
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

        $command = new TestCommandHarness([], $basePath);
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
            '--filter=Example',
        ]);
        $variables = $command->paratestEnvironmentVariablesPublic();

        $this->assertContains('--runner=' . ParallelRunner::class, $arguments);
        $this->assertContains('--filter=Example', $arguments);
        $this->assertSame(1, $variables['HYPERVEL_PARALLEL_TESTING']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_DROP_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE']);
        $this->assertSame('1', $variables[TestCommand::PROFILE_ENV]);
        $this->assertIsString($variables[TestCommand::PROFILE_DIRECTORY_ENV]);
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
    protected function writePhpunitConfiguration(string $extensions = ''): void
    {
        file_put_contents($this->app->basePath('phpunit.xml'), <<<XML
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
}
