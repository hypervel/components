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
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationRefreshCommandTest extends TestCase
{
    public function testRefreshCommandCallsCommandsWithProperArguments()
    {
        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, $events = m::mock(Dispatcher::class)->shouldIgnoreMissing());
        $command = new RefreshCommand();
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        $resetCommand = m::mock(ResetCommand::class);
        $migrateCommand = m::mock(MigrateCommand::class);

        $console->shouldReceive('find')->with('migrate:reset')->andReturn($resetCommand);
        $console->shouldReceive('find')->with('migrate')->andReturn($migrateCommand);
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $resetCommand->shouldReceive('run')->with(new InputMatcher("--force=1 {$quote}migrate:reset{$quote}"), m::any());
        $migrateCommand->shouldReceive('run')->with(new InputMatcher('--force=1 migrate'), m::any());

        $this->runCommand($command);
    }

    public function testRefreshCommandCallsCommandsWithStep()
    {
        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, $events = m::mock(Dispatcher::class)->shouldIgnoreMissing());
        $command = new RefreshCommand();
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        $rollbackCommand = m::mock(RollbackCommand::class);
        $migrateCommand = m::mock(MigrateCommand::class);

        $console->shouldReceive('find')->with('migrate:rollback')->andReturn($rollbackCommand);
        $console->shouldReceive('find')->with('migrate')->andReturn($migrateCommand);
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(DatabaseRefreshed::class));

        $quote = DIRECTORY_SEPARATOR === '\\' ? '"' : "'";
        $rollbackCommand->shouldReceive('run')->with(new InputMatcher("--step=2 --force=1 {$quote}migrate:rollback{$quote}"), m::any());
        $migrateCommand->shouldReceive('run')->with(new InputMatcher('--force=1 migrate'), m::any());

        $this->runCommand($command, ['--step' => 2]);
    }

    public function testRefreshCommandExitsWhenProhibited()
    {
        $app = new ApplicationDatabaseRefreshStub(['path.database' => __DIR__]);
        $dispatcher = $app->instance(Dispatcher::class, $events = m::mock(Dispatcher::class)->shouldIgnoreMissing());
        $command = new RefreshCommand();
        $console = m::mock(ConsoleApplication::class)->makePartial();
        $console->__construct();
        $command->setHypervel($app);
        $command->setApplication($console);

        RefreshCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $console->shouldNotHaveBeenCalled();
        $dispatcher->shouldNotReceive('dispatch');
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class InputMatcher extends m\Matcher\MatcherAbstract
{
    /**
     * @param \Symfony\Component\Console\Input\ArrayInput $actual
     */
    public function match(&$actual): bool
    {
        return (string) $actual === $this->_expected;
    }

    public function __toString(): string
    {
        return '';
    }
}

class ApplicationDatabaseRefreshStub extends Application
{
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

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
