<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use UnitEnum;

class DatabaseMigrationRefreshCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testRefreshCommandCallsCommandsWithProperArguments(): void
    {
        $command = new RefreshCommand;

        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $events = Double::for(Dispatcher::class)->strict();
        $dispatcher = $app->instance(Dispatcher::class, $events);
        $console = Double::for(new ConsoleApplication)->passthru();
        $command->setHypervel($app);
        $command->setApplication($console);

        $resetCommand = Double::for(ResetCommand::class)->strict();
        $migrateCommand = Double::for(MigrateCommand::class)->strict();

        $console->expects('find')->with('migrate:reset')->returns($resetCommand);
        $console->expects('find')->with('migrate')->returns($migrateCommand);
        $dispatcher->allows('hasListeners')->with(Argument::satisfies(fn (string $event): bool => $event !== DatabaseRefreshed::class))->returns(false);
        $dispatcher->expects('hasListeners')->with(DatabaseRefreshed::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $resetCommand->expects('setApplication')->with(Argument::same($console));
        $resetCommand->expects('setHypervel')->with(Argument::same($app));
        $resetCommand->expects('run')->with(Argument::satisfies(fn (ArrayInput $input): bool => (string) $input === "--force=1 {$quote}migrate:reset{$quote}"), Argument::any());
        $migrateCommand->expects('setApplication')->with(Argument::same($console));
        $migrateCommand->expects('setHypervel')->with(Argument::same($app));
        $migrateCommand->expects('run')->with(Argument::satisfies(fn (ArrayInput $input): bool => (string) $input === '--force=1 migrate'), Argument::any());

        $this->runCommand($command);
    }

    public function testRefreshCommandCallsCommandsWithStep(): void
    {
        $command = new RefreshCommand;

        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $events = Double::for(Dispatcher::class)->strict();
        $dispatcher = $app->instance(Dispatcher::class, $events);
        $console = Double::for(new ConsoleApplication)->passthru();
        $command->setHypervel($app);
        $command->setApplication($console);

        $rollbackCommand = Double::for(RollbackCommand::class)->strict();
        $migrateCommand = Double::for(MigrateCommand::class)->strict();

        $console->expects('find')->with('migrate:rollback')->returns($rollbackCommand);
        $console->expects('find')->with('migrate')->returns($migrateCommand);
        $dispatcher->allows('hasListeners')->with(Argument::satisfies(fn (string $event): bool => $event !== DatabaseRefreshed::class))->returns(false);
        $dispatcher->expects('hasListeners')->with(DatabaseRefreshed::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $rollbackCommand->expects('setApplication')->with(Argument::same($console));
        $rollbackCommand->expects('setHypervel')->with(Argument::same($app));
        $rollbackCommand->expects('run')->with(Argument::satisfies(fn (ArrayInput $input): bool => (string) $input === "--step=2 --force=1 {$quote}migrate:rollback{$quote}"), Argument::any());
        $migrateCommand->expects('setApplication')->with(Argument::same($console));
        $migrateCommand->expects('setHypervel')->with(Argument::same($app));
        $migrateCommand->expects('run')->with(Argument::satisfies(fn (ArrayInput $input): bool => (string) $input === '--force=1 migrate'), Argument::any());

        $this->runCommand($command, ['--step' => '2']);
    }

    #[DataProvider('failedCommandProvider')]
    public function testChildFailureStopsRefresh(string $failedCommand, array $options, array $expectedOperations, string $message): void
    {
        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, Double::for(Dispatcher::class)->strict());
        $dispatcher->allows('hasListeners')->returns(false);
        $command = $this->getMockBuilder(RefreshCommand::class)->onlyMethods(['call'])->getMock();
        $command->setHypervel($app);
        $operations = [];
        $command->expects($this->atLeastOnce())->method('call')->willReturnCallback(function (string $name) use ($failedCommand, &$operations): int {
            $operations[] = $name;

            return $name === $failedCommand ? 1 : 0;
        });
        $dispatcher->allows('hasListeners')->with(DatabaseRefreshed::class)->returns(true);
        $dispatcher->allows('dispatch')->with(Argument::type(DatabaseRefreshed::class))->resolves(function () use (&$operations): void {
            $operations[] = 'event';
        });
        $caught = null;

        try {
            $this->runCommand($command, $options + ['--seed' => true]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($message, $caught?->getMessage());
        $this->assertSame($expectedOperations, $operations);
    }

    /**
     * Get the failed refresh command scenarios.
     */
    public static function failedCommandProvider(): array
    {
        return [
            'reset' => [
                'migrate:reset', [], ['migrate:reset'],
                'Migration reset failed while refreshing the database.',
            ],
            'rollback' => [
                'migrate:rollback', ['--step' => '2'], ['migrate:rollback'],
                'Migration rollback failed while refreshing the database.',
            ],
            'migrate' => [
                'migrate', [], ['migrate:reset', 'migrate'],
                'Migration command failed while refreshing the database.',
            ],
            'seed' => [
                'db:seed', [], ['migrate:reset', 'migrate', 'event', 'db:seed'],
                'Database seeding failed after the database was refreshed.',
            ],
        ];
    }

    public function testRefreshCommandExitsWhenProhibited(): void
    {
        $command = new RefreshCommand;

        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, Double::for(Dispatcher::class)->strict());
        $dispatcher->allows('hasListeners')->resolves(static fn (string $event): bool => $event === DatabaseRefreshed::class);
        $dispatcher->expects('dispatch')->never();
        $console = Double::for(new ConsoleApplication)->passthru();
        $command->setHypervel($app);
        $command->setApplication($console);

        RefreshCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $console->received('find')->never();
    }

    /**
     * Run the refresh command.
     */
    protected function runCommand(RefreshCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseRefreshStub extends Application
{
    /**
     * Create a new test application instance.
     */
    public function __construct(array $data = [])
    {
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }

    /**
     * Get the application environment.
     */
    public function environment(UnitEnum|array|string ...$environments): bool|string
    {
        return 'development';
    }
}
