<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use LogicException;
use Override;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseSQLiteBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateDatabase(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        $builder = $connection->getSchemaBuilder();

        File::expects('put')
            ->with('my_temporary_database_a', '')
            ->andReturn(20); // bytes

        $this->assertTrue($builder->createDatabase('my_temporary_database_a'));

        File::expects('put')
            ->with('my_temporary_database_b', '')
            ->andReturn(false);

        $this->assertFalse($builder->createDatabase('my_temporary_database_b'));
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));

        $builder = $connection->getSchemaBuilder();

        File::expects('exists')
            ->andReturn(true);

        File::expects('delete')
            ->with('my_temporary_database_b')
            ->andReturn(true);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_b'));

        File::expects('exists')
            ->andReturn(false);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_c'));

        File::expects('exists')
            ->andReturn(true);

        File::expects('delete')
            ->with('my_temporary_database_c')
            ->andReturn(false);

        $this->assertFalse($builder->dropDatabaseIfExists('my_temporary_database_c'));
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testCreateDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));

        File::shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
        );

        (new SQLiteBuilder($connection))->createDatabase($name);
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testDropDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));

        File::shouldReceive('exists')->never();
        File::shouldReceive('delete')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
        );

        (new SQLiteBuilder($connection))->dropDatabaseIfExists($name);
    }

    public static function nonFileDatabaseNames(): array
    {
        return [
            'literal memory database' => [':memory:'],
            'memory URI database' => ['file::memory:'],
            'file URI database' => ['file:/tmp/database.sqlite?mode=rwc'],
        ];
    }

    public function testExecuteBlueprintWrapsAKnownRebuildAndMaintainsForeignKeyStateOutsideTheTransaction(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('executeSessionStatement')->with('pragma foreign_keys = 0')->ordered();
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback())
            ->ordered();
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();
        $connection->expects('executeSessionStatement')->with('pragma foreign_keys = 1')->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintPreservesDisabledForeignKeysOutsideTheTransaction(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('executeSessionStatement')->never();
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapASingleStatement(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->never();
        $connection->expects('transactionLevel')->never();
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('statement')->returns(true);

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapCommandsAddedByAnExtensionGrammar(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteBuilderExtensionGrammar($connection);

        $connection->expects('getSchemaGrammar')->times(2)->returns($grammar);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->never();
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('extension statement one')->returns(true)->ordered();
        $connection->expects('statement')->with('extension statement two')->returns(true)->ordered();

        $blueprint = new SQLiteBuilderExtensionBlueprint(
            $connection,
            'users',
            fn (SQLiteBuilderExtensionBlueprint $table) => $table->extensionCommand(),
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintWrapsFrameworkCommandsOverriddenByAnExtensionGrammar(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteBuilderExtensionGrammar($connection);

        $connection->expects('getSchemaGrammar')->times(2)->returns($grammar);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('overridden alter')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        $blueprint = new SQLiteBuilderExtensionBlueprint(
            $connection,
            'users',
            fn (SQLiteBuilderExtensionBlueprint $table) => $table->frameworkAlter(),
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintRestoresForeignKeysWhenTheTransactionFails(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'failing statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('executeSessionStatement')->with('pragma foreign_keys = 0')->ordered();
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback())
            ->ordered();
        $connection->expects('statement')
            ->with('failing statement')
            ->throws(new LogicException('statement failed'))
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma foreign_keys = 1')->ordered();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('statement failed');

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintCommitsSchemaBeforeForeignKeyRestorationFailureMarksSessionUnknown(): void
    {
        $pdo = new SQLiteBuilderFailingForeignKeyRestorePdo('sqlite::memory:');
        $connection = new SQLiteConnection(
            $pdo,
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite'],
        );
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();
        $connection->useDefaultSchemaGrammar();
        $pdo->exec('pragma foreign_keys = 1');

        $blueprint = $this->executionBlueprint(
            [
                'pragma foreign_keys = 0',
                'create table committed_schema_change (id integer primary key)',
                'pragma foreign_keys = 1',
            ],
            [new Fluent(['name' => 'alter'])],
        );
        $failure = $pdo->foreignKeyRestorationFailure = new PDOException('Failed to restore foreign key constraints.');

        try {
            try {
                (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
                $this->fail('Expected foreign-key restoration to fail.');
            } catch (PDOException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertFalse($connection->isReusable());
            $this->assertSame(0, (int) $pdo->query('pragma foreign_keys')->fetchColumn());
            $this->assertSame(
                1,
                (int) $pdo->query(
                    "select exists (select 1 from sqlite_master where type = 'table' and name = 'committed_schema_change')"
                )->fetchColumn(),
            );
        } finally {
            $connection->disconnect();
        }
    }

    public function testExecuteBlueprintRejectsAPopulatedRebuildInsideATransactionWithForeignKeysEnabled(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('getTablePrefix')->returns('');
        $blueprint->expects('getTable')->times(2)->returns('users');
        $connection->expects('scalar')
            ->with('select exists (select 1 from "users" limit 1)', [], false)
            ->returns(1);
        $connection->expects('transaction')->never();
        $connection->expects('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'SQLite cannot rebuild the populated table [users] while foreign key constraints are enabled within an active transaction.'
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintUsesASavepointForAnEmptyRebuildInsideATransaction(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('getTablePrefix')->returns('');
        $blueprint->expects('getTable')->returns('users');
        $connection->expects('scalar')
            ->with('select exists (select 1 from "users" limit 1)', [], false)
            ->returns(0);
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintUsesASavepointInsideATransactionWhenForeignKeysAreDisabled(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(false);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('scalar')->never();
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotMutateSessionOrTransactionStateWhilePretending(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('pretending')->returns(true);
        $connection->expects('transactionLevel')->never();
        $connection->expects('transaction')->never();
        $connection->expects('executeSessionStatement')->never();
        $connection->expects('statement')->with('pragma foreign_keys = 0')->returns(true)->ordered();
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();
        $connection->expects('statement')->with('pragma foreign_keys = 1')->returns(true)->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testChangingForeignKeyConstraintsInsideATransactionFailsBeforeExecutingThePragma(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'SQLite foreign key constraints cannot be enabled or disabled within an active transaction.'
        );

        (new SQLiteBuilder($connection))->disableForeignKeyConstraints();
    }

    public function testPragmaReadsStateFromTheWriteConnection(): void
    {
        $connection = $this->sqliteConnection();
        $readPdo = new PDO('sqlite::memory:');
        $connection->getPdo()->exec('pragma foreign_keys = 1');
        $readPdo->exec('pragma foreign_keys = 0');
        $connection->setReadPdo($readPdo);

        try {
            $this->assertSame(1, $connection->getSchemaBuilder()->pragma('foreign_keys'));
        } finally {
            $connection->disconnect();
        }
    }

    public function testSchemaStateReadsStoredTableDefinitionFromTheWriteConnection(): void
    {
        $connection = $this->sqliteConnection();
        $readPdo = new PDO('sqlite::memory:');
        $connection->statement('create table contacts (email varchar primary key) without rowid');
        $readPdo->exec('create table contacts (email varchar primary key)');
        $connection->setReadPdo($readPdo);

        try {
            $state = $connection->getSchemaBuilder()->getColumnsForSchemaState('contacts');

            $this->assertMatchesRegularExpression('/\bwithout\s+rowid\s*$/i', $state['sql']);
        } finally {
            $connection->disconnect();
        }
    }

    public function testHasTableReadsStateFromTheWriteConnection(): void
    {
        $connection = $this->sqliteConnection();
        $readPdo = new PDO('sqlite::memory:');
        $connection->statement('create table write_only (id integer primary key)');
        $connection->setReadPdo($readPdo);

        try {
            $this->assertTrue($connection->getSchemaBuilder()->hasTable('write_only'));
        } finally {
            $connection->disconnect();
        }
    }

    public function testWithoutForeignKeyConstraintsPreservesEnabledStateAcrossNestedBuilders(): void
    {
        $connection = $this->sqliteConnection();
        $outer = $connection->getSchemaBuilder();
        $inner = $connection->getSchemaBuilder();
        $outer->enableForeignKeyConstraints();

        $result = $outer->withoutForeignKeyConstraints(
            function () use ($connection, $inner): string {
                $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));

                return $inner->withoutForeignKeyConstraints(function () use ($connection): string {
                    $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));

                    return 'result';
                });
            }
        );

        $this->assertSame('result', $result);
        $this->assertSame(1, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testWithoutForeignKeyConstraintsPreservesDisabledState(): void
    {
        $connection = $this->sqliteConnection();
        $builder = $connection->getSchemaBuilder();

        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));

        $builder->withoutForeignKeyConstraints(function () use ($connection): void {
            $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));
        });

        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testWithoutForeignKeyConstraintsRejectsAnEnabledStateInsideATransaction(): void
    {
        $connection = $this->sqliteConnection();
        $builder = $connection->getSchemaBuilder();
        $builder->enableForeignKeyConstraints();
        $connection->beginTransaction();
        $callbackCalled = false;

        try {
            $builder->withoutForeignKeyConstraints(function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
            $this->fail('Expected the SQLite transaction restriction to be enforced.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'SQLite foreign key constraints cannot be enabled or disabled within an active transaction.',
                $exception->getMessage()
            );
        } finally {
            $connection->rollBack();
        }

        $this->assertFalse($callbackCalled);
        $this->assertSame(1, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testWithoutForeignKeyConstraintsAllowsAnAlreadyDisabledStateInsideATransaction(): void
    {
        $connection = $this->sqliteConnection();
        $builder = $connection->getSchemaBuilder();
        $connection->beginTransaction();

        try {
            $result = $builder->withoutForeignKeyConstraints(fn () => 'result');
        } finally {
            $connection->rollBack();
        }

        $this->assertSame('result', $result);
        $this->assertSame(0, (int) $connection->scalar('pragma foreign_keys'));
    }

    public function testDropAllTablesUsesGuardedCatalogCleanup(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0)->ordered();
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(0)->ordered();
        $connection->expects('getServerVersion')->returns('3.45.0')->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = RESET')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileRebuild('main'))
            ->returns(true)
            ->ordered();

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllViewsRestoresAnEnabledWritableSchema(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0)->ordered();
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(1)->ordered();
        $connection->expects('getServerVersion')->returns('3.45.0')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllViews('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = RESET')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileRebuild('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();

        (new SQLiteBuilder($connection))->dropAllViews();
    }

    public function testDropAllTablesReloadsTheSchemaAfterADeleteFailure(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(0);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables('main'))
            ->returns(false)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = RESET')->ordered();
        $connection->expects('statement')->with($grammar->compileRebuild('main'))->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'Failed to execute schema statement [delete from "main".sqlite_master where type in (\'table\', \'index\', \'trigger\')].'
        );

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesMarksALegacySessionUnknownWhenVacuumFails(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(0);
        $connection->expects('getServerVersion')->returns('3.36.0');
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 0')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileRebuild('main'))
            ->returns(false)
            ->ordered();
        $connection->expects('markCurrentSessionStateUnknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to execute schema statement [vacuum "main"].');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesKeepsAModernSessionKnownWhenVacuumFailsAfterReset(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(0);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = RESET')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileRebuild('main'))
            ->returns(false)
            ->ordered();
        $connection->expects('markCurrentSessionStateUnknown')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to execute schema statement [vacuum "main"].');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesStopsBeforeSchemaReloadWhenWritableSchemaResetFails(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $failure = new PDOException('Failed to reset writable_schema.');

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(0);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = 1')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')
            ->with('pragma writable_schema = RESET')
            ->throws($failure)
            ->ordered();
        $connection->expects('statement')->with($grammar->compileRebuild('main'))->never();

        try {
            (new SQLiteBuilder($connection))->dropAllTables();
            $this->fail('Expected writable_schema reset to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testDropAllViewsPropagatesWritableModeRestorationFailure(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new SQLiteGrammar($connection);
        $failure = new PDOException('Failed to restore writable_schema.');

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('scalar')->with('pragma writable_schema', [], false)->returns(1);
        $connection->expects('getServerVersion')->returns('3.45.0');
        $connection->expects('statement')
            ->with($grammar->compileDropAllViews('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('pragma writable_schema = RESET')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileRebuild('main'))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')
            ->with('pragma writable_schema = 1')
            ->throws($failure)
            ->ordered();

        try {
            (new SQLiteBuilder($connection))->dropAllViews();
            $this->fail('Expected writable_schema restoration to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testDropAllTablesRejectsAnActiveTransactionBeforeInspectingTheSchema(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('scalar')->never();
        $connection->expects('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SQLite cannot drop all tables within an active transaction.');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllViewsRejectsAnActiveTransactionBeforeInspectingTheSchema(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('scalar')->never();
        $connection->expects('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SQLite cannot drop all views within an active transaction.');

        (new SQLiteBuilder($connection))->dropAllViews();
    }

    public function testRefreshDatabaseFileUsesTheCanonicalMainDatabasePath(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDatabaseName')->returns('file:database.sqlite?mode=rwc');
        $connection->expects('scalar')->with('pragma journal_mode', [], false)->returns('delete');

        $builder = Double::for(new SQLiteBuilder($connection))->passthru();
        $builder->expects('getSchemas')->returns([
            ['name' => 'main', 'path' => '/canonical/database.sqlite'],
        ]);

        File::shouldReceive('put')->once()->with('/canonical/database.sqlite', '')->andReturn(0);

        $builder->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileRejectsWalForTheConnectedDatabase(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDatabaseName')->returns('/database.sqlite');
        $connection->expects('scalar')->with('pragma journal_mode', [], false)->returns('wal');
        $connection->expects('selectFromWriteConnection')->never();
        File::shouldReceive('put')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs(
            'SQLite database files cannot be refreshed through a connection using WAL journal mode. Use dropAllTables() to empty a database while connections are using it.'
        );

        (new SQLiteBuilder($connection))->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileRejectsAnActiveTransactionBeforeInspectingTheDatabase(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('getDatabaseName')->never();
        $connection->expects('scalar')->never();
        File::shouldReceive('put')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('SQLite cannot refresh the database file within an active transaction.');

        (new SQLiteBuilder($connection))->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileWithAnExplicitPathDoesNotInspectTheConnection(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->never();
        $connection->expects('getDatabaseName')->never();
        $connection->expects('scalar')->never();
        $connection->expects('selectFromWriteConnection')->never();
        File::shouldReceive('put')->once()->with('/database.sqlite', '')->andReturn(0);

        (new SQLiteBuilder($connection))->refreshDatabaseFile('/database.sqlite');
    }

    #[DataProvider('inMemoryDatabaseNames')]
    public function testRefreshDatabaseFileRejectsInMemoryDatabasesWithoutWritingAFile(string $database): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('getDatabaseName')->returns($database);
        $connection->expects('scalar')->never();
        File::shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            "SQLite database management requires a plain filesystem path; [{$database}] is not supported."
        );

        (new SQLiteBuilder($connection))->refreshDatabaseFile();
    }

    /**
     * Provide in-memory SQLite database names.
     */
    public static function inMemoryDatabaseNames(): array
    {
        return [
            'literal memory database' => [':memory:'],
            'memory URI database' => ['file::memory:?cache=shared'],
            'mode memory URI database' => ['file:workflow?mode=memory&cache=shared'],
        ];
    }

    public function testRefreshDatabaseFileThrowsWhenTheFileCannotBeWritten(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $connection->expects('getSchemaGrammar')->returns(new SQLiteGrammar($connection));

        File::shouldReceive('put')
            ->once()
            ->with('/database.sqlite', '')
            ->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to refresh SQLite database file [/database.sqlite].');

        (new SQLiteBuilder($connection))->refreshDatabaseFile('/database.sqlite');
    }

    /**
     * Create a Blueprint double for execution-boundary tests.
     *
     * @param list<string> $statements
     * @param list<Fluent> $commands
     */
    protected function executionBlueprint(array $statements, array $commands): Blueprint
    {
        $blueprint = Double::for(Blueprint::class)->strict();
        $blueprint->expects('toSql')->returns($statements);
        $blueprint->allows('getCommands')->returns($commands);

        return $blueprint;
    }

    /**
     * Create a SQLite connection with its default database components.
     */
    protected function sqliteConnection(): SQLiteConnection
    {
        $connection = new SQLiteConnection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => 'test', 'driver' => 'sqlite']
        );
        $connection->useDefaultQueryGrammar();
        $connection->useDefaultPostProcessor();
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }
}

class SQLiteBuilderFailingForeignKeyRestorePdo extends PDO
{
    public ?PDOException $foreignKeyRestorationFailure = null;

    /**
     * Execute an SQL statement and return the number of affected rows.
     */
    #[Override]
    public function exec(string $statement): int|false
    {
        if ($this->foreignKeyRestorationFailure !== null && trim($statement) === 'pragma foreign_keys = 1') {
            throw $this->foreignKeyRestorationFailure;
        }

        return parent::exec($statement);
    }
}

class SQLiteBuilderExtensionBlueprint extends Blueprint
{
    /**
     * Add an extension command.
     */
    public function extensionCommand(): Fluent
    {
        return $this->addCommand('extensionCommand');
    }

    /**
     * Add a framework alter command.
     */
    public function frameworkAlter(): Fluent
    {
        return $this->addCommand('alter');
    }
}

class SQLiteBuilderExtensionGrammar extends SQLiteGrammar
{
    /**
     * Compile an extension command.
     *
     * @return list<string>
     */
    public function compileExtensionCommand(Blueprint $blueprint, Fluent $command): array
    {
        return ['extension statement one', 'extension statement two'];
    }

    /**
     * Compile an alter command.
     *
     * @return list<string>
     */
    #[Override]
    public function compileAlter(Blueprint $blueprint, Fluent $command): array
    {
        return ['overridden alter', 'second statement'];
    }
}
