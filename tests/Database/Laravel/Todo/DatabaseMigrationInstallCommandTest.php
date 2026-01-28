<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Todo;

use Hypervel\Tests\TestCase;
use Illuminate\Database\Console\Migrations\InstallCommand;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Foundation\Application;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationInstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TODO: Port once illuminate/console package is ported
        $this->markTestSkipped('Requires illuminate/console package to be ported first.');
    }

    public function testFireCallsRepositoryToInstall()
    {
        $command = new InstallCommand($repo = m::mock(MigrationRepositoryInterface::class));
        $command->setLaravel(new Application());
        $repo->shouldReceive('setSource')->once()->with('foo');
        $repo->shouldReceive('createRepository')->once();
        $repo->shouldReceive('repositoryExists')->once()->andReturn(false);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testFireCallsRepositoryToInstallExists()
    {
        $command = new InstallCommand($repo = m::mock(MigrationRepositoryInterface::class));
        $command->setLaravel(new Application());
        $repo->shouldReceive('setSource')->once()->with('foo');
        $repo->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    protected function runCommand($command, $options = [])
    {
        return $command->run(new ArrayInput($options), new NullOutput());
    }
}
