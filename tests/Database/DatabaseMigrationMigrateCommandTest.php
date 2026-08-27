<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Console\CommandMutex;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Events\SchemaLoaded;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\SchemaState;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Foundation\Application;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class DatabaseMigrationMigrateCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        RecordingMigrationMySqlConnection::reset();
        RecordingMigrationPostgresConnection::reset();

        parent::tearDown();
    }

    public function testBasicMigrationsCallMigratorWithProperArguments(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('getNotes')->andReturn([]);

        $this->runCommand($command);
    }

    public function testMigrationsCanBeRunWithStoredSchema(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(false);
        $migrator->shouldReceive('resolveConnection')->andReturn($connection = m::mock(Connection::class));
        $connection->shouldReceive('getName')->andReturn('mysql');
        $migrator->shouldReceive('deleteRepository')->once();
        $connection->shouldReceive('getSchemaState')->andReturn($schemaState = m::mock(SchemaState::class));
        $schemaState->shouldReceive('handleOutputUsing')->andReturnSelf();
        $schemaState->shouldReceive('load')->once()->with(__DIR__ . '/Fixtures/schema.sql');
        $dispatcher->shouldReceive('dispatch')->once()->with(m::type(SchemaLoaded::class));
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $migrator->shouldReceive('getNotes')->andReturn([]);

        $this->runCommand($command, ['--schema-path' => __DIR__ . '/Fixtures/schema.sql']);
    }

    public function testMigrationRepositoryCreatedWhenNecessary(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $params = [$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)];
        $command = $this->getMockBuilder(MigrateCommand::class)->onlyMethods(['callSilent'])->setConstructorArgs($params)->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, repositoryExists: false);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $command->expects($this->once())->method('callSilent')->with($this->equalTo('migrate:install'), $this->equalTo([]));

        $this->runCommand($command);
    }

    public function testTheCommandMayBePretended(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => false]);

        $this->runCommand($command, ['--pretend' => true]);
    }

    public function testTheDatabaseMayBeSet(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'foo');
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testStepMayBeSet(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => true]);

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
        $this->expectMigrationPreflight($migrator);
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $command->expects($this->once())->method('call')->with('db:seed', [
            '--class' => 'Database\Seeders\CustomSeeder',
            '--force' => true,
        ]);

        $this->runCommand($command, ['--seed' => true, '--seeder' => 'Database\Seeders\CustomSeeder']);
    }

    public function testSeedOptionForwardsDatabaseToSeedCommand(): void
    {
        // migrate --database=X --seed must forward --database=X to db:seed
        // so the seeder runs on the user's chosen connection rather than
        // silently falling back to database.default.
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['call'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'pgsql-pooled');
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once();
        $command->expects($this->once())->method('call')->with('db:seed', [
            '--database' => 'pgsql-pooled',
            '--class' => 'Database\Seeders\DatabaseSeeder',
            '--force' => true,
        ]);

        $this->runCommand($command, ['--database' => 'pgsql-pooled', '--seed' => true]);
    }

    public function testMigrateWithSeedDoesNotLeakConnectionStateOrMutateConfig(): void
    {
        // Regression guard: after migrate --database=X --seed completes, the
        // worker-global config('database.default') must be untouched and the
        // coroutine Context must not retain any connection-default override.
        // This catches regressions where MigrateCommand itself starts
        // mutating config or Context in a way that escapes the command boundary.
        $config = new Repository(['database' => ['default' => 'pgsql']]);

        $app = new ApplicationDatabaseMigrationStub([
            'path.database' => __DIR__,
            'config' => $config,
        ]);
        $app->useDatabasePath(__DIR__);

        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['call'])
            ->setConstructorArgs([$migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class)])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'pgsql-pooled');
        $migrator->shouldReceive('hasRunAnyMigrations')->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once();
        $command->expects($this->once())->method('call');

        $contextBefore = \Hypervel\Context\CoroutineContext::get(
            \Hypervel\Database\ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY
        );

        $this->runCommand($command, ['--database' => 'pgsql-pooled', '--seed' => true]);

        $this->assertSame(
            'pgsql',
            $config->get('database.default'),
            'config("database.default") must not be mutated by migrate --seed',
        );

        $this->assertSame(
            $contextBefore,
            \Hypervel\Context\CoroutineContext::get(
                \Hypervel\Database\ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY
            ),
            'Context key must be in the same state as before the command ran',
        );
    }

    public function testAllTargetsAreInspectedAndMissingSqliteDatabaseIsCreatedBeforeMigration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);

        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new MigrateCommand($migrator = m::mock(Migrator::class), $dispatcher = m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $currentConnection = null;
        $secondaryInspections = 0;

        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, null)->andReturn(['default', 'analytics']);
        $migrator->shouldReceive('usingConnection')->times(4)->andReturnUsing(
            function ($name, $callback) use (&$currentConnection) {
                $previousConnection = $currentConnection;
                $currentConnection = $name;

                try {
                    return $callback();
                } finally {
                    $currentConnection = $previousConnection;
                }
            }
        );
        $migrator->shouldReceive('repositoryExists')->times(4)->andReturnUsing(
            function () use (&$currentConnection, &$secondaryInspections, $path): bool {
                if ($currentConnection === 'analytics') {
                    ++$secondaryInspections;

                    if ($secondaryInspections === 1) {
                        throw new SQLiteDatabaseDoesNotExistException($path);
                    }

                    $this->assertFileExists($path);
                }

                return true;
            }
        );
        $migrator->shouldReceive('hasRunAnyMigrations')->once()->andReturn(true);
        $migrator->shouldReceive('setOutput')->once()->andReturn($migrator);
        $migrator->shouldReceive('run')->once()->with($paths, ['pretend' => false, 'step' => false])->andReturnUsing(
            function () use ($path, &$secondaryInspections): array {
                $this->assertFileExists($path);
                $this->assertSame(2, $secondaryInspections);

                return [];
            }
        );

        try {
            $this->assertSame(0, $this->runCommand($command, ['--force' => true]));
        } finally {
            @unlink($path);
        }
    }

    public function testPretendRefusesToCreateAMissingDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);
        $command = $this->makeCommandWithMissingSqliteTarget($path);
        $exception = null;

        try {
            $this->runCommand($command, ['--pretend' => true, '--force' => true]);
        } catch (RuntimeException $throwable) {
            $exception = $throwable;
        }

        $this->assertSame(
            'Cannot pretend migrations because databases are missing for connections [analytics].',
            $exception?->getMessage(),
        );
        $this->assertFileDoesNotExist($path);
    }

    public function testMissingDatabaseRequiresForceInNonInteractiveMode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);
        $command = $this->makeCommandWithMissingSqliteTarget($path);
        $exception = null;

        try {
            $this->runCommand($command, ['--no-interaction' => true]);
        } catch (RuntimeException $throwable) {
            $exception = $throwable;
        }

        $this->assertSame(
            'Missing databases for connections [analytics] cannot be created in non-interactive mode without --force.',
            $exception?->getMessage(),
        );
        $this->assertFileDoesNotExist($path);
    }

    public function testDecliningMissingDatabaseCreationCreatesNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hypervel-sqlite-');
        unlink($path);
        $command = $this->makeCommandWithMissingSqliteTarget($path);
        $command->declineConfirmations = true;
        $exception = null;

        try {
            $this->runCommand($command);
        } catch (RuntimeException $throwable) {
            $exception = $throwable;
        }

        $this->assertSame('Databases were not created. Aborting migration.', $exception?->getMessage());
        $this->assertFileDoesNotExist($path);
    }

    public function testMissingDatabaseClassifierRecognizesOnlySupportedSignals(): void
    {
        $migrator = m::mock(Migrator::class);
        $mysqlConnection = m::mock(Connection::class);
        $mysqlConnection->shouldReceive('getDriverName')->andReturn('mysql');
        $postgresConnection = m::mock(Connection::class);
        $postgresConnection->shouldReceive('getDriverName')->andReturn('pgsql');
        $postgresConnection->shouldReceive('getDatabaseName')->andReturn('analytics');
        $migrator->shouldReceive('resolveConnection')->andReturnUsing(
            fn (string $name): Connection => $name === 'mysql' ? $mysqlConnection : $postgresConnection
        );
        $command = new TestableMigrateCommand($migrator, m::mock(Dispatcher::class));

        $sqliteCause = new SQLiteDatabaseDoesNotExistException('/missing.sqlite');
        $mysqlCause = new PDOException('Unknown database', 1049);
        $postgresCause = new PDOException('database "analytics" does not exist');
        $postgresCause->errorInfo = ['08006'];
        $otherPostgresCause = new PDOException('database "other" does not exist');
        $otherPostgresCause->errorInfo = ['08006'];
        $authenticationCause = new PDOException('Access denied', 1045);

        $this->assertSame(
            $sqliteCause,
            $command->probeFindMissingDatabaseCause('sqlite', new RuntimeException('wrapped', 0, $sqliteCause)),
        );
        $this->assertSame(
            $mysqlCause,
            $command->probeFindMissingDatabaseCause('mysql', new RuntimeException('wrapped', 0, $mysqlCause)),
        );
        $this->assertSame($postgresCause, $command->probeFindMissingDatabaseCause('pgsql', $postgresCause));
        $this->assertNull($command->probeFindMissingDatabaseCause('pgsql', $otherPostgresCause));
        $this->assertNull($command->probeFindMissingDatabaseCause('pgsql', $mysqlCause));
        $this->assertNull($command->probeFindMissingDatabaseCause('mysql', $authenticationCause));
        $this->assertNull($command->probeFindMissingDatabaseCause('mysql', new RuntimeException('network failure')));
    }

    public function testMySqlAdminCreationUsesResolvedWriteConfigurationAndQuotesTheCompleteIdentifier(): void
    {
        $app = new ApplicationDatabaseMigrationStub;
        $factory = new ConnectionFactory($app);
        $app->instance('db.factory', $factory);
        Connection::resolverFor(
            'mysql',
            fn (PDO|Closure $pdo, string $database, string $prefix, array $config): RecordingMigrationMySqlConnection => new RecordingMigrationMySqlConnection($pdo, $database, $prefix, $config),
        );
        $connection = $factory->make([
            'url' => 'mysql://root:secret@url-host/top_database',
            'prefix' => 'top_',
            'read' => ['host' => 'read-host', 'database' => 'read_database'],
            'write' => ['host' => 'write-host', 'database' => 'tenant.with`quote', 'prefix' => 'write_'],
            Connection::READ_WRITE_TYPE_CONFIG_KEY => 'write',
        ], 'mysql');
        $command = new TestableMigrateCommand(m::mock(Migrator::class), m::mock(Dispatcher::class));
        $command->setHypervel($app);

        $command->probeCreateMissingServerDatabase($connection);

        $this->assertCount(2, RecordingMigrationMySqlConnection::$instances);
        $adminConnection = RecordingMigrationMySqlConnection::$instances[1];
        $this->assertSame('', $adminConnection->getDatabaseName());
        $this->assertSame('write-host', $adminConnection->getConfig('host'));
        $this->assertSame('root', $adminConnection->getConfig('username'));
        $this->assertSame('secret', $adminConnection->getConfig('password'));
        $this->assertSame('write_', $adminConnection->getTablePrefix());
        $this->assertSame('write', $adminConnection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertSame('CREATE DATABASE IF NOT EXISTS `tenant.with``quote`', $adminConnection->executedSql);
        $this->assertTrue($adminConnection->disconnected);
    }

    public function testPostgresAdminCreationRemovesConnectViaDatabaseAndDisconnectsAfterFailure(): void
    {
        $app = new ApplicationDatabaseMigrationStub;
        $factory = new ConnectionFactory($app);
        $app->instance('db.factory', $factory);
        Connection::resolverFor(
            'pgsql',
            fn (PDO|Closure $pdo, string $database, string $prefix, array $config): RecordingMigrationPostgresConnection => new RecordingMigrationPostgresConnection($pdo, $database, $prefix, $config),
        );
        $connection = $factory->make([
            'driver' => 'pgsql',
            'database' => 'top_database',
            'host' => 'top-host',
            'prefix' => 'top_',
            'connect_via_database' => 'tenant.with"quote',
            'connect_via_port' => 6543,
            'read' => ['host' => 'read-host', 'database' => 'read_database'],
            'write' => ['host' => 'write-host', 'database' => 'tenant.with"quote', 'prefix' => 'write_'],
            Connection::READ_WRITE_TYPE_CONFIG_KEY => 'write',
        ], 'pgsql');
        $failure = new RuntimeException('create failed');
        RecordingMigrationPostgresConnection::$unpreparedException = $failure;
        $command = new TestableMigrateCommand(m::mock(Migrator::class), m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $caught = null;

        try {
            $command->probeCreateMissingServerDatabase($connection);
        } catch (RuntimeException $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($failure, $caught);
        $this->assertCount(2, RecordingMigrationPostgresConnection::$instances);
        $adminConnection = RecordingMigrationPostgresConnection::$instances[1];
        $this->assertSame('postgres', $adminConnection->getDatabaseName());
        $this->assertSame('write-host', $adminConnection->getConfig('host'));
        $this->assertSame(6543, $adminConnection->getConfig('connect_via_port'));
        $this->assertNull($adminConnection->getConfig('connect_via_database'));
        $this->assertSame('write_', $adminConnection->getTablePrefix());
        $this->assertSame('write', $adminConnection->getConfig(Connection::READ_WRITE_TYPE_CONFIG_KEY));
        $this->assertSame('CREATE DATABASE "tenant.with""quote"', $adminConnection->executedSql);
        $this->assertTrue($adminConnection->disconnected);
    }

    private function expectMigrationPreflight(
        Migrator $migrator,
        ?string $database = null,
        bool $repositoryExists = true,
    ): void {
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];

        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, $database)->andReturn([$database ?? 'default']);
        $migrator->shouldReceive('usingConnection')->twice()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->twice()->andReturn($repositoryExists);
    }

    private function makeCommandWithMissingSqliteTarget(string $path): TestableMigrateCommand
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new TestableMigrateCommand($migrator = m::mock(Migrator::class), m::mock(Dispatcher::class));
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $migrator->shouldReceive('paths')->once()->andReturn([]);
        $migrator->shouldReceive('getMigrationConnections')->once()->with($paths, null)->andReturn(['analytics']);
        $migrator->shouldReceive('usingConnection')->once()->andReturnUsing(function ($name, $callback) {
            return $callback();
        });
        $migrator->shouldReceive('repositoryExists')->once()->andThrow(new SQLiteDatabaseDoesNotExistException($path));
        $migrator->shouldReceive('run')->never();

        return $command;
    }

    protected function runCommand(MigrateCommand $command, array $input = []): int
    {
        if (! $command->getDefinition()->hasOption('no-interaction')) {
            $command->getDefinition()->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE));
        }

        return $command->run(new ArrayInput($input), new NullOutput);
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
    public bool $declineConfirmations = false;

    protected function configurePrompts(InputInterface $input): void
    {
        parent::configurePrompts($input);

        if ($this->declineConfirmations) {
            ConfirmPrompt::fallbackUsing(fn (ConfirmPrompt $prompt): bool => false);
            Prompt::fallbackWhen(true);
        }
    }

    public function probeFindMissingDatabaseCause(string $connectionName, Throwable $throwable): ?Throwable
    {
        return $this->findMissingDatabaseCause($connectionName, $throwable);
    }

    public function probeCreateMissingServerDatabase(Connection $connection): void
    {
        $this->createMissingServerDatabase($connection);
    }
}

trait RecordsMigrationAdminConnection
{
    /** @var list<static> */
    public static array $instances = [];

    public static ?RuntimeException $unpreparedException = null;

    public ?string $executedSql = null;

    public bool $disconnected = false;

    public function unprepared(string $query): bool
    {
        $this->executedSql = $query;

        if (static::$unpreparedException !== null) {
            throw static::$unpreparedException;
        }

        return true;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public static function reset(): void
    {
        static::$instances = [];
        static::$unpreparedException = null;
    }
}

class RecordingMigrationMySqlConnection extends MySqlConnection
{
    use RecordsMigrationAdminConnection;

    public function __construct(PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        static::$instances[] = $this;
    }
}

class RecordingMigrationPostgresConnection extends PostgresConnection
{
    use RecordsMigrationAdminConnection;

    public function __construct(PDO|Closure $pdo, string $database = '', string $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        static::$instances[] = $this;
    }
}
