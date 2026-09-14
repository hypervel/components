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

class DatabaseMigrationResetCommandTest extends TestCase
{
    public function testResetCommandCallsMigratorWithProperArguments(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new ResetCommand($migrator);
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->with(null, m::type(Closure::class))->andReturnUsing(function (?string $connection, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('repositoryExists')->andReturn(true);
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('reset')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], false);

        $this->runCommand($command);
    }

    public function testResetCommandCanBePretended(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new ResetCommand($migrator);
        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);
        $migrator->expects('paths')->andReturn([]);
        $migrator->expects('usingConnection')->with('foo', m::type(Closure::class))->andReturnUsing(function (?string $connection, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('repositoryExists')->andReturn(true);
        $migrator->expects('setOutput')->andReturn($migrator);
        $migrator->expects('reset')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], true);

        $this->runCommand($command, ['--pretend' => true, '--database' => 'foo']);
    }

    public function testResetCommandExitsWhenProhibited(): void
    {
        $migrator = m::mock(Migrator::class);
        $command = new ResetCommand($migrator);

        $app = new ApplicationDatabaseResetStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command->setHypervel($app);

        ResetCommand::prohibit();

        $code = $this->runCommand($command);

        $this->assertSame(1, $code);

        $migrator->shouldNotHaveReceived('paths');
    }

    /**
     * Run the reset command.
     */
    protected function runCommand(ResetCommand $command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseResetStub extends Application
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
