<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Foundation\Console\DevCommand;
use Hypervel\Foundation\DevCommandColor;
use Hypervel\Foundation\DevCommands;
use Hypervel\Support\Contracts\NodePackageManager as NodePackageManagerContract;
use Hypervel\Support\Facades\File;
use Hypervel\Support\NodePackageManager;
use Hypervel\Support\NodePackageManagers\Bun;
use Hypervel\Support\NodePackageManagers\Npm;
use Hypervel\Support\NodePackageManagers\Pnpm;
use Hypervel\Support\NodePackageManagers\Yarn;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

class DevCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DevCommands::flushState();
        $this->app->setRunningInConsole(true);
    }

    public function testMultiplexCommandDefaultsToTabs(): void
    {
        config(['app.name' => 'Hypervel']);

        $this->assertSame(
            '@laravel/multiplex --title ' . escapeshellarg('artisan dev · ' . basename(base_path()))
                . " 'server@#93c5fd,php artisan watch' 'vite@#fcd34d,npm run dev'",
            $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands())
        );
    }

    public function testMultiplexCommandUsesAppNameInTitleWhenSet(): void
    {
        config(['app.name' => 'Acme']);

        $this->assertStringContainsString(
            "--title 'artisan dev · Acme'",
            $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands())
        );
    }

    public function testMultiplexCommandKeepsColonsInLabels(): void
    {
        $command = $this->devCommand()->buildMultiplexCommandForTesting([
            ['name' => 'queue:work', 'command' => 'php artisan queue:work', 'source' => [], 'color' => '#c4b5fd'],
        ]);

        $this->assertStringContainsString("'queue:work@#c4b5fd,php artisan queue:work'", $command);
    }

    public function testMultiplexCommandModeFlags(): void
    {
        $this->assertStringContainsString('--stream', $this->devCommand(['--stream' => true])->buildMultiplexCommandForTesting($this->devCommands()));
        $this->assertStringContainsString('--inline', $this->devCommand(['--inline' => true])->buildMultiplexCommandForTesting($this->devCommands()));
        $this->assertStringNotContainsString('--tabs', $this->devCommand(['--tabs' => true])->buildMultiplexCommandForTesting($this->devCommands()));
    }

    public function testMultiplexCommandUsesConfiguredModeWhenNoFlagGiven(): void
    {
        DevCommands::stream();

        $this->assertStringContainsString('--stream', $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands()));
    }

    public function testMultiplexCommandModeFlagOverridesConfiguredMode(): void
    {
        DevCommands::stream();

        $command = $this->devCommand(['--tabs' => true])->buildMultiplexCommandForTesting($this->devCommands());

        $this->assertStringNotContainsString('--stream', $command);
        $this->assertStringNotContainsString('--inline', $command);
    }

    public function testMultiplexCommandTimestampsFromFlagOrConfiguration(): void
    {
        $this->assertStringContainsString('--timestamps', $this->devCommand(['--timestamps' => true])->buildMultiplexCommandForTesting($this->devCommands()));

        DevCommands::withTimestamps();

        $this->assertStringContainsString('--timestamps', $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands()));
    }

    public function testMultiplexCommandNoRestartFromFlagOrConfiguration(): void
    {
        $this->assertStringNotContainsString('--no-restart', $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands()));
        $this->assertStringContainsString('--no-restart', $this->devCommand(['--no-restart' => true])->buildMultiplexCommandForTesting($this->devCommands()));

        DevCommands::disableAutoRestart();

        $this->assertStringContainsString('--no-restart', $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands()));
    }

    public function testMultiplexCommandJsonFlag(): void
    {
        $this->assertStringContainsString('--json', $this->devCommand(['--json' => true])->buildMultiplexCommandForTesting($this->devCommands()));
    }

    public function testMultiplexCommandBufferSizes(): void
    {
        DevCommands::bufferSize(1000);
        DevCommands::streamBufferSize(2000);

        $command = $this->devCommand()->buildMultiplexCommandForTesting($this->devCommands());

        $this->assertStringContainsString("--buffer-size='1000'", $command);
        $this->assertStringContainsString("--stream-buffer-size='2000'", $command);

        $command = $this->devCommand([
            '--buffer-size' => '50',
            '--stream-buffer-size' => '60',
        ])->buildMultiplexCommandForTesting($this->devCommands());

        $this->assertStringContainsString("--buffer-size='50'", $command);
        $this->assertStringContainsString("--stream-buffer-size='60'", $command);
    }

    // REMOVED: The three concurrently command tests cover the Windows-only runner.

    public function testEmptyEffectiveCommandListFailsCleanly(): void
    {
        DevCommands::registerDefaults();
        DevCommands::only('missing');

        $tester = $this->commandTester(new Application);

        $this->assertSame(SymfonyCommand::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('No development commands are configured to run.', $tester->getDisplay());
    }

    public function testMissingWatcherFailsWithActionableError(): void
    {
        DevCommands::registerDefaults();

        $tester = $this->commandTester(new Application);

        $this->assertSame(SymfonyCommand::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('composer require --dev hypervel/watcher', $tester->getDisplay());
    }

    public function testFilteringOutDefaultServerBypassesWatcherValidation(): void
    {
        DevCommands::registerDefaults();
        DevCommands::except('server');

        $packageManager = $this->captureProcessCommand();

        $this->executeUntilProcessCommand(new Application);

        $this->assertNotNull($packageManager->command);
        $this->assertStringNotContainsString('php artisan watch', $packageManager->command);
    }

    public function testReplacingDefaultServerBypassesWatcherValidation(): void
    {
        DevCommands::registerDefaults();
        DevCommands::register('custom-server', 'server');

        $packageManager = $this->captureProcessCommand();

        $this->executeUntilProcessCommand(new Application);

        $this->assertNotNull($packageManager->command);
        $this->assertStringContainsString('custom-server', $packageManager->command);
    }

    public function testWatcherPresentProceedsToProcessCommandConstruction(): void
    {
        DevCommands::registerDefaults();

        $application = new Application;
        $application->addCommand(new SymfonyCommand('watch'));
        $packageManager = $this->captureProcessCommand();

        $this->executeUntilProcessCommand($application);

        $this->assertNotNull($packageManager->command);
        $this->assertStringContainsString('@laravel/multiplex', $packageManager->command);
        $this->assertStringContainsString('php artisan watch', $packageManager->command);
    }

    public function testProcessCommandArgumentsAreShellEscaped(): void
    {
        config(['app.name' => "Acme's App"]);
        $command = 'printf "%s\n" "$HOME" && php -r \'echo "quoted value";\'';
        $name = 'quoted process';

        DevCommands::register($command, $name)->blue();

        $packageManager = $this->captureProcessCommand();

        $this->executeUntilProcessCommand(new Application);

        $this->assertSame(
            'npx @laravel/multiplex --title ' . escapeshellarg("artisan dev · Acme's App")
                . ' ' . escapeshellarg($name . '@' . DevCommandColor::BLUE->value . ',' . $command),
            $packageManager->command
        );
    }

    public function testCommandsAreConsumedOnce(): void
    {
        File::shouldReceive('exists')->with(base_path('package.json'))->once()->andReturnTrue();
        DevCommands::registerDefaults();

        for ($index = 1; $index <= 5; ++$index) {
            DevCommands::register("command-{$index}", "command-{$index}");
        }

        $application = new Application;
        $application->addCommand(new SymfonyCommand('watch'));
        $this->captureProcessCommand();

        $this->executeUntilProcessCommand($application);

        $colorCount = (new ReflectionClass(DevCommands::class))->getProperty('colorCount')->getValue();

        $this->assertSame(2, $colorCount);
    }

    public function testCommandDoesNotRunInACoroutine(): void
    {
        $defaults = (new ReflectionClass(DevCommand::class))->getDefaultProperties();

        $this->assertFalse($defaults['coroutine']);
    }

    #[DataProvider('packageManagers')]
    public function testMultiplexUsesThePackageManagersExecutableName(string $managerClass, string $expectedPrefix): void
    {
        config(['app.name' => 'Acme']);
        DevCommands::register('command', 'custom');
        $packageManager = $this->captureProcessCommand(new $managerClass);

        $this->executeUntilProcessCommand(new Application);

        $this->assertSame(
            $expectedPrefix . " --title 'artisan dev · Acme' 'custom@" . DevCommandColor::BLUE->value . ",command'",
            $packageManager->command
        );
    }

    /**
     * Provide package managers and their Multiplex invocation prefixes.
     *
     * @return array<string, array{class-string<NodePackageManagerContract>, string}>
     */
    public static function packageManagers(): array
    {
        return [
            'npm' => [Npm::class, 'npx @laravel/multiplex'],
            'bun' => [Bun::class, 'bunx @laravel/multiplex'],
            'pnpm' => [Pnpm::class, 'pnpm exec multiplex'],
            'yarn' => [Yarn::class, 'yarn run multiplex'],
            'custom' => [CustomDevPackageManager::class, 'custom exec @laravel/multiplex'],
        ];
    }

    /**
     * Create a command with the given options.
     */
    protected function devCommand(array $options = []): DevCommand
    {
        $command = new class extends DevCommand {
            /**
             * Expose Multiplex command construction for testing.
             */
            public function buildMultiplexCommandForTesting(array $devCommands): string
            {
                return $this->buildMultiplexCommand($devCommands);
            }
        };

        $command->setInput(new ArrayInput($options, $command->getDefinition()));

        return $command;
    }

    /**
     * Provide development commands for Multiplex output tests.
     */
    protected function devCommands(): array
    {
        return [
            ['name' => 'server', 'command' => 'php artisan watch', 'source' => [], 'color' => '#93c5fd'],
            ['name' => 'vite', 'command' => 'npm run dev', 'source' => [], 'color' => '#fcd34d'],
        ];
    }

    /**
     * Bind a package manager that captures the orchestrator command.
     */
    protected function captureProcessCommand(?NodePackageManagerContract $manager = null): CapturingNodePackageManager
    {
        $packageManager = new CapturingNodePackageManager($manager ?? new Npm);
        $this->app->instance(NodePackageManager::class, $packageManager);

        return $packageManager;
    }

    /**
     * Execute the command until process orchestration would begin.
     */
    protected function executeUntilProcessCommand(Application $application): void
    {
        try {
            $this->commandTester($application)->execute([]);
            $this->fail('Expected process command construction to stop the test.');
        } catch (ProcessCommandConstructed) {
        }
    }

    /**
     * Create a tester for the development command.
     */
    protected function commandTester(Application $application): CommandTester
    {
        $command = new DevCommand;
        $command->setHypervel($this->app);
        $application->addCommand($command);

        return new CommandTester($command);
    }
}

class CapturingNodePackageManager extends NodePackageManager
{
    public ?string $command = null;

    /**
     * Capture the process command without launching it.
     */
    public function getExecCommand(string $command): string
    {
        $this->command = parent::getExecCommand($command);

        throw new ProcessCommandConstructed;
    }
}

class CustomDevPackageManager implements NodePackageManagerContract
{
    /**
     * Determine whether this package manager is selected automatically.
     */
    public static function matches(): bool
    {
        return false;
    }

    /**
     * Build a custom script command.
     */
    public function getRunCommand(string $command): string
    {
        return "custom run {$command}";
    }

    /**
     * Build a custom package execution command.
     */
    public function getExecCommand(string $command): string
    {
        return "custom exec {$command}";
    }
}

class ProcessCommandConstructed extends RuntimeException
{
}
