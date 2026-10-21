<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Console\CommandMutex;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Console\Migrations\InstallCommand;
use Hypervel\Database\Migrations\MigrationRepositoryInterface;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class DatabaseMigrationInstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        CoroutineContext::forget(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        parent::tearDown();
    }

    public function testFireCallsRepositoryToInstall(): void
    {
        $app = new ApplicationDatabaseInstallStub;
        $repository = m::mock(MigrationRepositoryInterface::class);
        $command = new InstallCommand($repository);
        $command->setHypervel($app);
        $repository->expects('setSource')->with('foo');
        $repository->expects('createRepository');
        $repository->expects('repositoryExists')->andReturn(false);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testFireCallsRepositoryToInstallExists(): void
    {
        $app = new ApplicationDatabaseInstallStub;
        $repository = m::mock(MigrationRepositoryInterface::class);
        $command = new InstallCommand($repository);
        $command->setHypervel($app);
        $repository->expects('setSource')->with('foo');
        $repository->expects('repositoryExists')->andReturn(true);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testSetSourceReceivesSwappedNameWhenMigrationsConnectionConfigured(): void
    {
        $app = new ApplicationDatabaseInstallStub;
        $app->instance('config', new Repository([
            'database' => [
                'connections' => [
                    'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                    'pgsql' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        $repository = m::mock(MigrationRepositoryInterface::class);
        $command = new InstallCommand($repository);
        $command->setHypervel($app);
        $repository->expects('setSource')->with('pgsql');
        $repository->expects('repositoryExists')->andReturn(false);
        $repository->expects('createRepository');

        $this->runCommand($command, ['--database' => 'pgsql-pooled']);
    }

    public function testSetSourceRoutesThroughDefaultWhenNoDatabaseOptionGiven(): void
    {
        // Regression for the null-handling fix: when no --database is passed
        // and the configured default is a pooled connection with a
        // migrations_connection sibling, install must still route to the
        // direct connection rather than landing on the pooled one.
        $app = new ApplicationDatabaseInstallStub;
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'pgsql-pooled',
                'connections' => [
                    'pgsql-pooled' => ['driver' => 'pgsql', 'migrations_connection' => 'pgsql'],
                    'pgsql' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        $repository = m::mock(MigrationRepositoryInterface::class);
        $command = new InstallCommand($repository);
        $command->setHypervel($app);
        $repository->expects('setSource')->with('pgsql');
        $repository->expects('repositoryExists')->andReturn(true);

        $this->runCommand($command);
    }

    public function testSetSourceHonorsContextOverrideWhenNoDatabaseOptionGiven(): void
    {
        // End-to-end regression for the "effective default" fix at the
        // command level. Scenario: a caller wraps the command in
        // DB::usingConnection('tenant-pooled', ...) so Context holds the
        // scoped default. config.default is unrelated. migrate:install must
        // route to the Context target's migrations_connection, not to the
        // configured default's sibling.
        $app = new ApplicationDatabaseInstallStub;
        $app->instance('config', new Repository([
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => ['driver' => 'pgsql'],
                    'tenant-pooled' => [
                        'driver' => 'pgsql',
                        'migrations_connection' => 'tenant-direct',
                    ],
                    'tenant-direct' => ['driver' => 'pgsql'],
                ],
            ],
        ]));

        CoroutineContext::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, 'tenant-pooled');

        $repository = m::mock(MigrationRepositoryInterface::class);
        $command = new InstallCommand($repository);
        $command->setHypervel($app);
        $repository->expects('setSource')->with('tenant-direct');
        $repository->expects('repositoryExists')->andReturn(true);

        $this->runCommand($command);
    }

    /**
     * Run the command with the given options.
     */
    protected function runCommand(InstallCommand $command, array $options = []): int
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
