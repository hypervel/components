<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Console\CommandMutex;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Console\Migrations\MigrateCommand;
use Hypervel\Database\Events\SchemaLoaded;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Schema\MySqlSchemaState;
use Hypervel\Database\Schema\SchemaState;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class DatabaseMigrationMigrateCommandTest extends TestCase
{
    use VerifiesDoubles;

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
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventsDispatcher;
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);

        $this->runCommand($command);
    }

    public function testMigrationsCanBeRunWithStoredSchema(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventFake(new EventsDispatcher);
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->expects('hasRunAnyMigrations')->returns(false);
        $connection = Double::for(MySqlConnection::class)->strict();
        $migrator->expects('resolveConnection')->returns($connection);
        $connection->expects('getName')->returns('mysql');
        $migrator->expects('deleteRepository');
        $schemaState = Double::for(MySqlSchemaState::class)->strict();
        $connection->expects('getSchemaState')->returns($schemaState);
        $schemaState->expects('handleOutputUsing')->returns($schemaState);
        $schemaState->expects('load')->with(__DIR__ . '/Fixtures/schema.sql');
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);

        $this->runCommand($command, ['--schema-path' => __DIR__ . '/Fixtures/schema.sql']);

        $dispatcher->assertDispatchedOnce(SchemaLoaded::class);
    }

    public function testStoredSchemaDoesNotDispatchWithoutListeners(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->expects('hasRunAnyMigrations')->returns(false);
        $connection = Double::for(Connection::class)->strict();
        $migrator->expects('resolveConnection')->returns($connection);
        $migrator->expects('deleteRepository');
        $schemaState = Double::for(SchemaState::class)->strict();
        $connection->expects('getSchemaState')->returns($schemaState);
        $schemaState->expects('handleOutputUsing')->returns($schemaState);
        $schemaState->expects('load')->with(__DIR__ . '/Fixtures/schema.sql');
        $dispatcher->expects('hasListeners')->with(SchemaLoaded::class)->returns(false);
        $dispatcher->expects('dispatch')->never();
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);

        $this->runCommand($command, ['--schema-path' => __DIR__ . '/Fixtures/schema.sql']);
    }

    public function testMigrationRepositoryCreatedWhenNecessary(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventsDispatcher;
        $params = [$migrator, $dispatcher];
        $command = $this->getMockBuilder(MigrateCommand::class)->onlyMethods(['callSilent'])->setConstructorArgs($params)->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, repositoryExists: false);
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $command->expects($this->once())->method('callSilent')->with('migrate:install', []);

        $this->runCommand($command);
    }

    public function testTheCommandMayBePretended(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventsDispatcher;
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => true, 'step' => false]);

        $this->runCommand($command, ['--pretend' => true]);
    }

    public function testTheDatabaseMayBeSet(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventsDispatcher;
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'foo');
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);

        $this->runCommand($command, ['--database' => 'foo']);
    }

    public function testStepMayBeSet(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $migrator = Double::for(Migrator::class)->strict();
        $dispatcher = new EventsDispatcher;
        $command = new MigrateCommand($migrator, $dispatcher);
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => true]);

        $this->runCommand($command, ['--step' => true]);
    }

    public function testGracefulReturnsSuccessWhenRunMigrationsThrows(): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['runMigrations'])
            ->setConstructorArgs([$migrator = Double::for(Migrator::class)->strict(), $dispatcher = Double::for(Dispatcher::class)->strict()])
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
            ->setConstructorArgs([$migrator = Double::for(Migrator::class)->strict(), $dispatcher = Double::for(Dispatcher::class)->strict()])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->allows('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with([__DIR__ . DIRECTORY_SEPARATOR . 'migrations'], ['pretend' => false, 'step' => false]);
        $command->expects($this->once())->method('call')->with('db:seed', [
            '--class' => 'Database\Seeders\CustomSeeder',
            '--force' => true,
        ]);

        $this->runCommand($command, ['--seed' => true, '--seeder' => 'Database\Seeders\CustomSeeder']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSeedFailureHonorsGracefulOption(bool $graceful): void
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = $this->getMockBuilder(MigrateCommand::class)
            ->onlyMethods(['call'])
            ->setConstructorArgs([$migrator = Double::for(Migrator::class)->strict(), Double::for(Dispatcher::class)->strict()])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator);
        $migrator->allows('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run');
        $command->expects($this->once())->method('call')->with('db:seed', [
            '--class' => 'Database\Seeders\DatabaseSeeder',
            '--force' => true,
        ])->willReturn(1);

        if (! $graceful) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIs('Database seeding failed after migrations ran.');
        }

        $code = $command->run(new ArrayInput([
            '--seed' => true,
            '--graceful' => $graceful,
        ]), $output = new BufferedOutput);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Database seeding failed after migrations ran.', $output->fetch());
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
            ->setConstructorArgs([$migrator = Double::for(Migrator::class)->strict(), $dispatcher = Double::for(Dispatcher::class)->strict()])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'pgsql-pooled');
        $migrator->allows('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run');
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
            ->setConstructorArgs([$migrator = Double::for(Migrator::class)->strict(), $dispatcher = Double::for(Dispatcher::class)->strict()])
            ->getMock();
        $command->setHypervel($app);
        $this->expectMigrationPreflight($migrator, 'pgsql-pooled');
        $migrator->allows('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run');
        $command->expects($this->once())->method('call');

        $contextBefore = CoroutineContext::get(
            ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY
        );

        $this->runCommand($command, ['--database' => 'pgsql-pooled', '--seed' => true]);

        $this->assertSame(
            'pgsql',
            $config->get('database.default'),
            'config("database.default") must not be mutated by migrate --seed',
        );

        $this->assertSame(
            $contextBefore,
            CoroutineContext::get(
                ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY
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
        $command = new MigrateCommand($migrator = Double::for(Migrator::class)->strict(), $dispatcher = Double::for(Dispatcher::class)->strict());
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $currentConnection = null;
        $secondaryInspections = 0;

        $migrator->expects('paths')->returns([]);
        $migrator->expects('getMigrationConnections')->with($paths, null)->returns(['default', 'analytics']);
        $migrator->expects('usingConnection')->times(4)->resolves(
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
        $migrator->expects('repositoryExists')->times(4)->resolves(
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
        $migrator->expects('hasRunAnyMigrations')->returns(true);
        $migrator->expects('setOutput')->returns($migrator);
        $migrator->expects('run')->with($paths, ['pretend' => false, 'step' => false])->resolves(
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
        $migrator = Double::for(Migrator::class)->strict();
        $mysqlConnection = Double::for(Connection::class)->strict();
        $mysqlConnection->allows('getDriverName')->returns('mysql');
        $postgresConnection = Double::for(Connection::class)->strict();
        $postgresConnection->allows('getDriverName')->returns('pgsql');
        $postgresConnection->allows('getDatabaseName')->returns('analytics');
        $migrator->allows('resolveConnection')->resolves(
            fn (string $name): Connection => $name === 'mysql' ? $mysqlConnection : $postgresConnection
        );
        $command = new TestableMigrateCommand($migrator, Double::for(Dispatcher::class)->strict());

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
        $command = new TestableMigrateCommand(Double::for(Migrator::class)->strict(), Double::for(Dispatcher::class)->strict());
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
        $command = new TestableMigrateCommand(Double::for(Migrator::class)->strict(), Double::for(Dispatcher::class)->strict());
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

    /**
     * Expect the connection preflight and migration repository checks.
     */
    private function expectMigrationPreflight(
        Migrator $migrator,
        ?string $database = null,
        bool $repositoryExists = true,
    ): void {
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];

        $migrator->expects('paths')->returns([]);
        $migrator->expects('getMigrationConnections')->with($paths, $database)->returns([$database ?? 'default']);
        $migrator->expects('usingConnection')->times(2)->resolves(function (?string $name, callable $callback): mixed {
            return $callback();
        });
        $migrator->expects('repositoryExists')->times(2)->returns($repositoryExists);
    }

    private function makeCommandWithMissingSqliteTarget(string $path): TestableMigrateCommand
    {
        $app = new ApplicationDatabaseMigrationStub(['path.database' => __DIR__]);
        $app->useDatabasePath(__DIR__);
        $command = new TestableMigrateCommand($migrator = Double::for(Migrator::class)->strict(), Double::for(Dispatcher::class)->strict());
        $command->setHypervel($app);
        $paths = [__DIR__ . DIRECTORY_SEPARATOR . 'migrations'];
        $migrator->expects('paths')->returns([]);
        $migrator->expects('getMigrationConnections')->with($paths, null)->returns(['analytics']);
        $migrator->expects('usingConnection')->resolves(function ($name, $callback) {
            return $callback();
        });
        $migrator->expects('repositoryExists')->throws(new SQLiteDatabaseDoesNotExistException($path));
        $migrator->expects('run')->never();

        return $command;
    }

    /**
     * Run the command with the given input.
     */
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
        $mutex = Double::for(CommandMutex::class)->strict();
        $mutex->allows('create')->returns(true);
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
