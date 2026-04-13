<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Console\CommandMutex;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationInstallCommandTest extends TestCase
{
    public function testFireCallsRepositoryToInstall()
    {
        $app = new ApplicationDatabaseInstallStub;
        $command = new InstallCommand($repo = m::mock(MigrationRepositoryInterface::class));
        $command->setHypervel($app);
        $repo->shouldReceive('setSource')->once()->with('foo');
        $repo->shouldReceive('createRepository')->once();
        $repo->shouldReceive('repositoryExists')->once()->andReturn(false);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testFireCallsRepositoryToInstallExists()
    {
        $app = new ApplicationDatabaseInstallStub;
        $command = new InstallCommand($repo = m::mock(MigrationRepositoryInterface::class));
        $command->setHypervel($app);
        $repo->shouldReceive('setSource')->once()->with('foo');
        $repo->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    protected function runCommand($command, $options = [])
    {
        return $command->run(new ArrayInput($options), new NullOutput);
    }
}

class ApplicationDatabaseInstallStub extends Application
{
    public function __construct()
    {
        $mutex = m::mock(CommandMutex::class);
        $mutex->shouldReceive('create')->andReturn(true);
        $mutex->shouldReceive('release')->andReturn(true);
        $this->instance(CommandMutex::class, $mutex);
        $this->instance('env', 'development');

        static::setInstance($this);
    }

    public function environment(...$environments): bool|string
    {
        return 'development';
    }
}
