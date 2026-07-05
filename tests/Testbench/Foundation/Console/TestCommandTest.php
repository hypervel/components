<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Testbench\Bootstrapper;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\TestCommand;
use Hypervel\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\parse_environment_variables;

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
            ['--colors=never', '--configuration=' . package_path('phpunit.xml.dist'), '--filter=Foundation'],
            $command->phpunitArgumentsPublic(['--configuration=ignored.xml', '--without-tty', '--filter=Foundation'])
        );
    }

    #[Test]
    public function itFiltersParallelOnlyOptionsFromPhpunitArgumentsForPackageTests(): void
    {
        $command = new TestCommandHarness;

        $arguments = $command->phpunitArgumentsPublic([
            '--parallel',
            '--drop-databases',
            '--without-cache',
            '--filter=Foundation',
        ]);

        $this->assertContains('--filter=Foundation', $arguments);
        $this->assertNotContains('--parallel', $arguments);
        $this->assertNotContains('--drop-databases', $arguments);
        $this->assertNotContains('--without-cache', $arguments);
    }

    #[Test]
    public function itBuildsPhpunitEnvironmentVariablesForPackageTests(): void
    {
        $command = new TestCommandHarness(['profile' => true]);
        $command->setHypervel($this->app);
        $variables = $command->phpunitEnvironmentVariablesPublic();

        $this->assertSame('testing', $variables['APP_ENV']);
        $this->assertSame('1', $variables[TestCommand::PROFILE_ENV]);
        $this->assertIsString($variables[TestCommand::PROFILE_DIRECTORY_ENV]);
        $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
        $this->assertSame(package_path(), $variables['TESTBENCH_WORKING_PATH']);
        $this->assertArrayNotHasKey('TESTBENCH_APP_BASE_PATH', $variables);
    }

    #[Test]
    public function itDoesNotForwardParentRuntimeEnvironmentVariablesForPackageTests(): void
    {
        $previousParentRuntimeServerExists = array_key_exists('HYPERVEL_TEST_PARENT_RUNTIME_ENV', $_SERVER);
        $previousParentRuntimeServer = $_SERVER['HYPERVEL_TEST_PARENT_RUNTIME_ENV'] ?? null;
        $previousParentRuntimeEnvironmentExists = array_key_exists('HYPERVEL_TEST_PARENT_RUNTIME_ENV', $_ENV);
        $previousParentRuntimeEnvironment = $_ENV['HYPERVEL_TEST_PARENT_RUNTIME_ENV'] ?? null;
        $previousRedisPasswordServerExists = array_key_exists('REDIS_PASSWORD', $_SERVER);
        $previousRedisPasswordServer = $_SERVER['REDIS_PASSWORD'] ?? null;
        $previousRedisPasswordEnvironmentExists = array_key_exists('REDIS_PASSWORD', $_ENV);
        $previousRedisPasswordEnvironment = $_ENV['REDIS_PASSWORD'] ?? null;

        try {
            $_SERVER['HYPERVEL_TEST_PARENT_RUNTIME_ENV'] = 'parent';
            $_ENV['HYPERVEL_TEST_PARENT_RUNTIME_ENV'] = 'parent';
            $_SERVER['REDIS_PASSWORD'] = 'null';
            $_ENV['REDIS_PASSWORD'] = 'null';

            $command = new TestCommandHarness;
            $command->setHypervel($this->app);
            $variables = $command->phpunitEnvironmentVariablesPublic();

            $this->assertArrayNotHasKey('HYPERVEL_TEST_PARENT_RUNTIME_ENV', $variables);
            $this->assertArrayNotHasKey('REDIS_PASSWORD', $variables);
            $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
        } finally {
            $this->restoreSuperglobalValue($_SERVER, 'HYPERVEL_TEST_PARENT_RUNTIME_ENV', $previousParentRuntimeServerExists, $previousParentRuntimeServer);
            $this->restoreSuperglobalValue($_ENV, 'HYPERVEL_TEST_PARENT_RUNTIME_ENV', $previousParentRuntimeEnvironmentExists, $previousParentRuntimeEnvironment);
            $this->restoreSuperglobalValue($_SERVER, 'REDIS_PASSWORD', $previousRedisPasswordServerExists, $previousRedisPasswordServer);
            $this->restoreSuperglobalValue($_ENV, 'REDIS_PASSWORD', $previousRedisPasswordEnvironmentExists, $previousRedisPasswordEnvironment);
        }
    }

    #[Test]
    public function itForwardsConfiguredEnvironmentVariablesForPackageTests(): void
    {
        $this->withTestbenchConfiguration([
            'env' => parse_environment_variables([
                'HYPERVEL_TEST_PACKAGE_ENV' => 'configured',
                'HYPERVEL_TEST_EMPTY_ENV' => '',
                'HYPERVEL_TEST_FALSE_ENV' => false,
            ]),
        ], function (): void {
            $command = new TestCommandHarness;
            $command->setHypervel($this->app);
            $variables = $command->phpunitEnvironmentVariablesPublic();

            $this->assertSame('configured', $variables['HYPERVEL_TEST_PACKAGE_ENV']);
            $this->assertSame('', $variables['HYPERVEL_TEST_EMPTY_ENV']);
            $this->assertSame('(false)', $variables['HYPERVEL_TEST_FALSE_ENV']);
        });
    }

    #[Test]
    public function packageCommandVariablesOverrideConfiguredEnvironmentVariables(): void
    {
        $this->withTestbenchConfiguration([
            'env' => parse_environment_variables([
                'APP_ENV' => 'local',
                'TESTBENCH_PACKAGE_TESTER' => false,
                'TESTBENCH_WORKING_PATH' => '/tmp/wrong',
            ]),
        ], function (): void {
            $command = new TestCommandHarness;
            $command->setHypervel($this->app);
            $variables = $command->phpunitEnvironmentVariablesPublic();

            $this->assertSame('testing', $variables['APP_ENV']);
            $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
            $this->assertSame(package_path(), $variables['TESTBENCH_WORKING_PATH']);
            $this->assertArrayNotHasKey('TESTBENCH_APP_BASE_PATH', $variables);
        });
    }

    #[Test]
    public function itBuildsParatestArgumentsAndEnvironmentVariablesForPackageTests(): void
    {
        $command = new TestCommandHarness([
            'parallel' => true,
            'recreate-databases' => true,
            'drop-databases' => true,
            'without-databases' => true,
            'without-cache' => true,
        ]);
        $command->setHypervel($this->app);

        $arguments = $command->paratestArgumentsPublic([
            '--parallel',
            '--drop-databases',
            '--without-cache',
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
        $this->assertTrue($variables['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE']);
        $this->assertSame('(true)', $variables['TESTBENCH_PACKAGE_TESTER']);
        $this->assertSame(package_path(), $variables['TESTBENCH_WORKING_PATH']);
        $this->assertArrayNotHasKey('TESTBENCH_APP_BASE_PATH', $variables);
    }

    /**
     * Run a callback with temporary Testbench configuration.
     *
     * @param array<string, mixed> $attributes
     */
    private function withTestbenchConfiguration(array $attributes, callable $callback): void
    {
        $reflection = new ReflectionClass(Bootstrapper::class);
        $previousConfiguration = $reflection->getStaticPropertyValue('configuration');

        try {
            $reflection->setStaticPropertyValue('configuration', new Config($attributes));

            $callback();
        } finally {
            $reflection->setStaticPropertyValue('configuration', $previousConfiguration);
        }
    }

    /**
     * Restore a superglobal value.
     *
     * @param array<string, mixed> $values
     */
    private function restoreSuperglobalValue(array &$values, string $key, bool $exists, mixed $value): void
    {
        if (! $exists) {
            unset($values[$key]);

            return;
        }

        $values[$key] = $value;
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
