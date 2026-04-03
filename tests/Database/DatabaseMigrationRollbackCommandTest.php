<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\RollbackCommand;
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
class DatabaseMigrationRollbackCommandTest extends TestCase
{
    public function testRollbackCommandCallsMigratorWithProperArguments()
    {
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command);
    }

    public function testRollbackCommandCallsMigratorWithStepOption()
    {
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--step' => 2]);
    }

    public function testRollbackCommandCanBePretended()
    {
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testRollbackCommandCanBePretendedWithStepOption()
    {
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo', '--step' => 2]);
    }

    public function testRollbackCommandCallsMigratorWithBatchOption(): void
    {
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 3]);

        $this->runCommand($command, ['--batch' => 3]);
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class ApplicationDatabaseRollbackStub extends Application
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
