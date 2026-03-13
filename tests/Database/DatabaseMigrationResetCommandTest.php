<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\ResetCommand;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationResetCommandTest extends TestCase
{
    public function testResetCommandCallsMigratorWithProperArguments()
    {
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new ResetCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->with(null, m::type(Closure::class))->andReturnUsing(function ($connection, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('reset')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], false);

        $this->runCommand($command);
    }

    public function testResetCommandCanBePretended()
    {
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new ResetCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->with('foo', m::type(Closure::class))->andReturnUsing(function ($connection, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('reset')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], true);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testResetCommandExitsWhenProhibited()
    {
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new ResetCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);

        ResetCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $migrator->shouldNotHaveBeenCalled();
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class ApplicationDatabaseResetStub extends Application
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
