<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Todo;

use Closure;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Mockery as m;
use Hypervel\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationResetCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TODO: Port once illuminate/console package is ported
        $this->markTestSkipped('Requires illuminate/console package to be ported first.');
    }

    protected function tearDown(): void
    {
        ResetCommand::prohibit(false);

        parent::tearDown();
    }

    public function testResetCommandCallsMigratorWithProperArguments()
    {
        $command = new ResetCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->with(null, m::type(Closure::class))->andReturnUsing(function ($connection, $callback) {
            $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('reset')->once()->with([__DIR__.DIRECTORY_SEPARATOR.'migrations'], false);

        $this->runCommand($command);
    }

    public function testResetCommandCanBePretended()
    {
        $command = new ResetCommand($migrator = m::mock(Migrator::class));
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('usingConnection')->once()->with('foo', m::type(Closure::class))->andReturnUsing(function ($connection, $callback) {
            $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('reset')->once()->with([__DIR__.DIRECTORY_SEPARATOR.'migrations'], true);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testRefreshCommandExitsWhenProhibited()
    {
        $command = new ResetCommand($migrator = m::mock(Migrator::class));

        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setLaravel($app);

        ResetCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $migrator->shouldNotHaveBeenCalled();
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

// TODO: Uncomment once illuminate/console package is ported
// class ApplicationDatabaseResetStub extends Application
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
