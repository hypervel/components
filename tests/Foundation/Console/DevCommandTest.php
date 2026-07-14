<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Foundation\Console\DevCommand;
use Hypervel\Foundation\DevCommands;
use Hypervel\Support\NodePackageManager;
use Hypervel\Testbench\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

class DevCommandTest extends TestCase
{
    protected string|false $previousColumns = false;

    protected function setUp(): void
    {
        parent::setUp();

        DevCommands::flushState();
        $this->app->setRunningInConsole(true);
        $this->previousColumns = getenv('COLUMNS');
        putenv('COLUMNS=120');
    }

    protected function tearDown(): void
    {
        $this->previousColumns === false
            ? putenv('COLUMNS')
            : putenv("COLUMNS={$this->previousColumns}");

        parent::tearDown();
    }

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
        $this->assertStringContainsString('concurrently', $packageManager->command);
        $this->assertStringContainsString('php artisan watch', $packageManager->command);
    }

    public function testCommandsAreConsumedOnce(): void
    {
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

    /**
     * Bind a package manager that captures the orchestrator command.
     */
    protected function captureProcessCommand(): CapturingNodePackageManager
    {
        $packageManager = new CapturingNodePackageManager;
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

    public function getExecCommand(string $command): string
    {
        $this->command = $command;

        throw new ProcessCommandConstructed;
    }
}

class ProcessCommandConstructed extends RuntimeException
{
}
