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

class DatabaseMigrationRollbackCommandTest extends TestCase
{
    public function testRollbackCommandCallsMigratorWithProperArguments(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->andReturnUsing(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command);
    }

    public function testRollbackCommandCallsMigratorWithStepOption(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->andReturnUsing(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--step' => 2]);
    }

    public function testRollbackCommandCanBePretended(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->andReturnUsing(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 0, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testRollbackCommandCanBePretendedWithStepOption(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->andReturnUsing(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo', '--step' => 2]);
    }

    public function testRollbackCommandCallsMigratorWithBatchOption(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new RollbackCommand($migrator);
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->andReturnUsing(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('rollback')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => 0, 'batch' => 3]);

        $this->runCommand($command, ['--batch' => 3]);
    }

    /**
     * Run the rollback command.
     */
    protected function runCommand(RollbackCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseRollbackStub extends Application
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
