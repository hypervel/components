<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Todo;

use Hypervel\Tests\TestCase;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationRollbackCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TODO: Port once illuminate/console package is ported
        $this->markTestSkipped('Requires illuminate/console package to be ported first.');
    }

    public function testRollbackCommandCallsMigratorWithProperArguments()
    {
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
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
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
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
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], true);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testRollbackCommandCanBePretendedWithStepOption()
    {
        $command = new RollbackCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseRollbackStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('rollback')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => 2, 'batch' => 0]);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo', '--step' => 2]);
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

// TODO: Uncomment once illuminate/console package is ported
// class ApplicationDatabaseRollbackStub extends Application
// {
//     public function __construct(array $data = [])
//     {
//         foreach ($data as $abstract => $instance) {
//             $this->instance($abstract, $instance);
//         }
//     }
//
//     public function environment(...$environments)
//     {
//         return 'development';
//     }
// }
