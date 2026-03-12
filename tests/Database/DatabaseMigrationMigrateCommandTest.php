<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Events\SchemaLoaded;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\SchemaState;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class DatabaseMigrationMigrateCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::setInstance(null);

        parent::tearDown();
    }

    public function testBasicMigrationsCallMigratorWithProperArguments()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('getNotes')->andReturn([]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command);
    }

    public function testMigrationsCanBeRunWithStoredSchema()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(false);
        $migrator->shouldReceive('resolveConnection')->andReturn($connection = m::mock(Connection::class));
        $connection->shouldReceive('getName')->andReturn('mysql');
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('deleteRepository')->once();
        $connection->shouldReceive('getSchemaState')->andReturn($schemaState = m::mock(SchemaState::class));
        $schemaState->shouldReceive('handleOutputUsing')->andReturnSelf();
        $schemaState->shouldReceive('load')->once()->with(__DIR__ . '/Fixtures/schema.sql');
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(SchemaLoaded::class));
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('getNotes')->andReturn([]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--schema-path' => __DIR__ . '/Fixtures/schema.sql']);
    }

    public function testMigrationRepositoryCreatedWhenNecessary()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $params = [$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)];
        $command = $this->getMockBuilder(MigrateCommand::class)->onlyMethods(['callSilent'])->setConstructorArgs($params)->getMock();
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(false);
        $command->expects($this->once())->method('callSilent')->with($this->equalTo('migrate:install'), $this->equalTo([]));

        $this->runCommand($command);
    }

    public function testTheCommandMayBePretended()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => false]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--pretend' => true]);
    }

    public function testTheDatabaseMayBeSet()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testStepMayBeSet()
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => true]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);

        $this->runCommand($command, ['--step' => true]);
    }

    public function testGracefulReturnsSuccessWhenRunMigrationsThrows(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['runMigrations'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)])
            ->getMock();
        $command->setHypervel($app);
        $command->expects($this->once())->method('runMigrations')->willThrowException(new RuntimeException('boom'));

        $code = $this->runCommand($command, ['--graceful' => true]);

        $this->assertSame(0, $code);
    }

    public function testSeedOptionRunsSeederAfterMigrations(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['call'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)])
            ->getMock();
        $command->setHypervel($app);
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(true);
        $command->expects($this->once())->method('call')->with('db:seed', [
            '--class' => 'Database\Seeders\CustomSeeder',
            '--force' => true,
        ]);

        $this->runCommand($command, ['--seed' => true, '--seeder' => 'Database\Seeders\CustomSeeder']);
    }

    public function testCreateMissingSqliteDatabaseWithForceOption(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);

        $app = new ApplicationDatabaseMigrationStub();
        $command = new TestableMigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->probeMode = 'sqlite';
        $command->sqlitePath = $path;
        $command->setHypervel($app);

        try {
            $code = $this->runCommand($command, ['--force' => true]);

            $this->assertSame(0, $code);
            $this->assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function testCreateMissingMysqlDatabaseUsesParsedUrlConfiguration(): void
    {
        $factory = m::mock(ConnectionFactory::class);
        $adminConnection = m::mock(Connection::class);
        $factory->shouldReceive('make')->once()->with(m::on(function (array $config): bool {
            return $config['driver'] === 'mysql'
                && $config['host'] === 'db'
                && $config['username'] === 'root'
                && $config['password'] === 'secret'
                && array_key_exists('database', $config)
                && $config['database'] === null;
        }), 'mysql')->andReturn($adminConnection);
        $adminConnection->shouldReceive('unprepared')->once()->with('CREATE DATABASE IF NOT EXISTS `missing_database`')->andReturn(true);
        $adminConnection->shouldReceive('disconnect')->once();

        $app = new ApplicationDatabaseMigrationStub([
            'config' => new Repository([
                'database' => [
                    'connections' => [
                        'mysql' => [
                            'url' => 'mysql://root:secret@db/missing_database',
                        ],
                    ],
                ],
            ]),
            ConnectionFactory::class => $factory,
        ]);
        $command = new TestableMigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->probeMode = 'mysql';
        $command->probeConnection = m::mock(Connection::class);
        $command->probeConnection->shouldReceive('getName')->andReturn('mysql');
        $command->probeConnection->shouldReceive('getDatabaseName')->andReturn('missing_database');
        $command->probeConnection->shouldReceive('getDriverName')->andReturn('mysql');
        $command->setHypervel($app);

        $code = $this->runCommand($command, ['--force' => true]);

        $this->assertSame(0, $code);
    }

    protected function runCommand($command, $input = [])
    {
        return $command->run(new ArrayInput($input), new NullOutput());
    }
}

class ApplicationDatabaseMigrationStub extends Application
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

class TestableMigrateCommand extends MigrateCommand
{
    public string $probeMode = '';

    public ?string $sqlitePath = null;

    public ?Connection $probeConnection = null;

    public function handle(): int
    {
        return match ($this->probeMode) {
            'sqlite' => $this->createMissingSqliteDatabase($this->sqlitePath) ? 0 : 1,
            'mysql' => $this->createMissingMySqlOrPgsqlDatabase($this->probeConnection) ? 0 : 1,
            default => parent::handle(),
        };
    }
}
