<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Console\Migrations\RefreshCommand;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Console\Migrations\RollbackCommand;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\Matcher\MatcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationRefreshCommandTest extends TestCase
{
    public function testRefreshCommandCallsCommandsWithProperArguments(): void
    {
        $command = new RefreshCommand;

        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $events = m::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher = $app->instance(Dispatcher::class, $events);
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        $resetCommand = m::mock(ResetCommand::class);
        $migrateCommand = m::mock(MigrateCommand::class);

        $console->expects('find')->with('migrate:reset')->andReturn($resetCommand);
        $console->expects('find')->with('migrate')->andReturn($migrateCommand);
        $dispatcher->shouldReceive('hasListeners')->once()->with(DatabaseRefreshed::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->with(m::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $resetCommand->shouldReceive('setApplication')->once()->with($console);
        $resetCommand->shouldReceive('setHypervel')->once()->with($app);
        $resetCommand->expects('run')->with(new InputMatcher("--force=1 {$quote}migrate:reset{$quote}"), m::any());
        $migrateCommand->shouldReceive('setApplication')->once()->with($console);
        $migrateCommand->shouldReceive('setHypervel')->once()->with($app);
        $migrateCommand->expects('run')->with(new InputMatcher('--force=1 migrate'), m::any());

        $this->runCommand($command);
    }

    public function testRefreshCommandCallsCommandsWithStep(): void
    {
        $command = new RefreshCommand;

        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $events = m::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher = $app->instance(Dispatcher::class, $events);
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        $rollbackCommand = m::mock(RollbackCommand::class);
        $migrateCommand = m::mock(MigrateCommand::class);

        $console->expects('find')->with('migrate:rollback')->andReturn($rollbackCommand);
        $console->expects('find')->with('migrate')->andReturn($migrateCommand);
        $dispatcher->shouldReceive('hasListeners')->once()->with(DatabaseRefreshed::class)->andReturnTrue();
        $dispatcher->expects('dispatch')->with(m::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $rollbackCommand->shouldReceive('setApplication')->once()->with($console);
        $rollbackCommand->shouldReceive('setHypervel')->once()->with($app);
        $rollbackCommand->expects('run')->with(new InputMatcher("--step=2 --force=1 {$quote}migrate:rollback{$quote}"), m::any());
        $migrateCommand->shouldReceive('setApplication')->once()->with($console);
        $migrateCommand->shouldReceive('setHypervel')->once()->with($app);
        $migrateCommand->expects('run')->with(new InputMatcher('--force=1 migrate'), m::any());

        $this->runCommand($command, ['--step' => '2']);
    }

    #[DataProvider('failedCommandProvider')]
    public function testChildFailureStopsRefresh(string $failedCommand, array $options, array $expectedOperations, string $message): void
    {
        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, m::mock(Dispatcher::class));
        $dispatcher->shouldReceive('hasListeners')->byDefault()->andReturnFalse();
        $command = $this->getMockBuilder(RefreshCommand::class)->onlyMethods(['call'])->getMock();
        $command->setHypervel($app);
        $operations = [];
        $command->expects($this->atLeastOnce())->method('call')->willReturnCallback(function (string $name) use ($failedCommand, &$operations): int {
            $operations[] = $name;

            return $name === $failedCommand ? 1 : 0;
        });
        $dispatcher->shouldReceive('hasListeners')->with(DatabaseRefreshed::class)->andReturnTrue();
        $dispatcher->shouldReceive('dispatch')->with(m::type(DatabaseRefreshed::class))->andReturnUsing(function () use (&$operations): void {
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
        $events = m::mock(Dispatcher::class)->shouldIgnoreMissing();
        $dispatcher = $app->instance(Dispatcher::class, $events);
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        RefreshCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $console->shouldNotHaveReceived('find');
        $dispatcher->shouldNotHaveReceived('dispatch');
    }

    /**
     * Run the refresh command.
     */
    protected function runCommand(RefreshCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class InputMatcher implements MatcherInterface
{
    /**
     * Create a new command input matcher.
     */
    public function __construct(protected string $expected)
    {
    }

    /**
     * Match the command input.
     *
     * @param ArrayInput $actual
     */
    public function match(mixed &$actual): bool
    {
        return (string) $actual === $this->expected;
    }

    /**
     * Get the string representation of the matcher.
     */
    public function __toString(): string
    {
        return '';
    }
}

class ApplicationDatabaseRefreshStub extends Application
{
    /**
     * Create a new test application instance.
     */
    public function __construct(array $data = [])
    {
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->andReturn(true);
        $mutex->shouldReceive('release')->andReturn(true);
        $this->instance(CommandMutex::class, $mutex);
        $this->instance('env', 'development');

        foreach ($data as $abstract => $instance) {
            $this->instance($abstract, $instance);
        }

        static::setInstance($this);
    }

    /**
     * Get the application environment.
     */
    public function environment(array|string ...$environments): bool|string
    {
        return 'development';
    }
}
