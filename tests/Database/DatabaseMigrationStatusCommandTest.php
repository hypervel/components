<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\StatusCommand;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
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
class DatabaseMigrationStatusCommandTest extends TestCase
{
    public function testPendingOptionReturnsConfiguredExitCodeAsInteger()
    {
        $app = new ApplicationDatabaseStatusStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new StatusCommand($migrator = m::mock(Migrator::class));
        $command->setHypervel($app);
        $repository = m::mock(MigrationRepositoryInterface::class);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $migrator->shouldReceive('getRepository')->twice()->andReturn($repository);
        $repository->shouldReceive('getRan')->once()->andReturn([]);
        $repository->shouldReceive('getMigrationBatches')->once()->andReturn([]);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'])->andReturn([
            '/tmp/2024_01_01_000000_create_users_table.php',
        ]);
        $migrator->shouldReceive('getMigrationName')->once()->with('/tmp/2024_01_01_000000_create_users_table.php')->andReturn('2024_01_01_000000_create_users_table');

        $code = $this->runCommand($command, ['--pending' => '5']);

        $this->assertSame(5, $code);
    }

    protected function runCommand($command, array $input = []): int
    {
        return $command->run(new ArrayInput($input), new NullOutput);
    }
}

class ApplicationDatabaseStatusStub extends Application
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
