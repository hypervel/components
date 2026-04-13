<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Testbench\Foundation\Console\TestCommand;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\package_path;

/**
 * @internal
 * @coversNothing
 */
class TestCommandTest extends TestCase
{
    #[Test]
    public function itResolvesThePhpunitConfigurationFileFromThePackageRoot(): void
    {
        $command = new TestCommandHarness;

        $this->assertSame(package_path('phpunit.xml.dist'), $command->phpUnitConfigurationFilePublic());
    }

    #[Test]
    public function itBuildsPackageRootBinaryPaths(): void
    {
        $phpunitCommand = new TestCommandHarness;
        $paratestCommand = new TestCommandHarness(['parallel' => true]);

        $this->assertSame(
            [PHP_BINARY, package_path('vendor', 'phpunit', 'phpunit', 'phpunit')],
            $phpunitCommand->binaryPublic()
        );

        $this->assertSame(
            [PHP_BINARY, package_path('vendor', 'brianium', 'paratest', 'bin', 'paratest')],
            $paratestCommand->binaryPublic()
        );
    }

    #[Test]
    public function itBuildsPhpunitArgumentsUsingThePackageConfigurationFile(): void
    {
        $command = new TestCommandHarness(['no-ansi' => true]);

        $this->assertSame(
            ['--colors=never', '--configuration=' . package_path('phpunit.xml.dist'), '--no-output', '--filter=Foundation'],
            $command->phpunitArgumentsPublic(['--configuration=ignored.xml', '--filter=Foundation'])
        );
    }

    #[Test]
    public function itBuildsPhpunitEnvironmentVariablesForPackageTests(): void
    {
        $command = new TestCommandHarness(['compact' => true, 'profile' => true]);
        $command->setHypervel($this->app);
        $variables = $command->phpunitEnvironmentVariablesPublic();

        $this->assertSame('testing', $variables['APP_ENV']);
        $this->assertSame('DefaultPrinter', $variables['COLLISION_PRINTER']);
        $this->assertSame('true', $variables['COLLISION_PRINTER_COMPACT']);
        $this->assertSame('true', $variables['COLLISION_PRINTER_PROFILE']);
        $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
        $this->assertSame(package_path(), $variables['TESTBENCH_WORKING_PATH']);
        $this->assertSame($this->app->basePath(), $variables['TESTBENCH_APP_BASE_PATH']);
    }

    #[Test]
    public function itBuildsParatestArgumentsAndEnvironmentVariablesForPackageTests(): void
    {
        $command = new TestCommandHarness([
            'parallel' => true,
            'recreate-databases' => true,
            'drop-databases' => true,
            'without-databases' => true,
        ]);
        $command->setHypervel($this->app);

        $arguments = $command->paratestArgumentsPublic([
            '--parallel',
            '--drop-databases',
            '--filter=Foundation',
            '--configuration=ignored.xml',
        ]);
        $variables = $command->paratestEnvironmentVariablesPublic();

        $this->assertContains('--configuration=' . package_path('phpunit.xml.dist'), $arguments);
        $this->assertContains('--runner=Hypervel\Testbench\Features\ParallelRunner', $arguments);
        $this->assertContains('--filter=Foundation', $arguments);
        $this->assertSame(1, $variables['HYPERVEL_PARALLEL_TESTING']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_RECREATE_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_DROP_DATABASES']);
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
        $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
        $this->assertSame(package_path(), $variables['TESTBENCH_WORKING_PATH']);
        $this->assertSame($this->app->basePath(), $variables['TESTBENCH_APP_BASE_PATH']);
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

        return $this->options[$key] ?? null;
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
     * Expose the resolved PHPUnit configuration file.
     */
    public function phpUnitConfigurationFilePublic(): string
    {
        return $this->phpUnitConfigurationFile();
    }

    /**
     * Expose the resolved binary command.
     *
     * @return array<int, string>
     */
    public function binaryPublic(): array
    {
        return $this->binary();
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
     * Expose Paratest arguments.
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
     * Expose Paratest environment variables.
     *
     * @return array<string, null|bool|int|string>
     */
    public function paratestEnvironmentVariablesPublic(): array
    {
        return $this->paratestEnvironmentVariables();
    }
}
