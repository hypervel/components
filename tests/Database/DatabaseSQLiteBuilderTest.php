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
use LogicException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseSQLiteBuilderTest extends TestCase
{
    public function testCreateDatabase()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        $builder = new SQLiteBuilder($connection);

        File::shouldReceive('put')
            ->once()
            ->with('my_temporary_database_a', '')
            ->andReturn(20); // bytes

        $this->assertTrue($builder->createDatabase('my_temporary_database_a'));

        File::shouldReceive('put')
            ->once()
            ->with('my_temporary_database_b', '')
            ->andReturn(false);

        $this->assertFalse($builder->createDatabase('my_temporary_database_b'));
    }

    public function testDropDatabaseIfExists()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        $builder = new SQLiteBuilder($connection);

        File::shouldReceive('exists')
            ->once()
            ->andReturn(true);

        File::shouldReceive('delete')
            ->once()
            ->with('my_temporary_database_b')
            ->andReturn(true);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_b'));

        File::shouldReceive('exists')
            ->once()
            ->andReturn(false);

        $this->assertTrue($builder->dropDatabaseIfExists('my_temporary_database_c'));

        File::shouldReceive('exists')
            ->once()
            ->andReturn(true);

        File::shouldReceive('delete')
            ->once()
            ->with('my_temporary_database_c')
            ->andReturn(false);

        $this->assertFalse($builder->dropDatabaseIfExists('my_temporary_database_c'));
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testCreateDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "SQLite database management requires a plain filesystem path; [{$name}] is not supported."
        );

        (new SQLiteBuilder($connection))->createDatabase($name);
    }

    #[DataProvider('nonFileDatabaseNames')]
    public function testDropDatabaseRejectsNonFileDatabaseNames(string $name): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('exists')->never();
        File::shouldReceive('delete')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
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
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 0')->andReturn(0)->ordered();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback())
            ->ordered();
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 1')->andReturn(0)->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintPreservesDisabledForeignKeysOutsideTheTransaction(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getPdo')->never();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapASingleStatement(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->never();
        $connection->shouldReceive('transactionLevel')->never();
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('statement')->andReturnTrue();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapCommandsAddedByAnExtensionGrammar(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteBuilderExtensionGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->twice()->andReturn($grammar);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->never();
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('extension statement one')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('extension statement two')->andReturnTrue()->ordered();

        $blueprint = new SQLiteBuilderExtensionBlueprint(
            $connection,
            'users',
            fn (SQLiteBuilderExtensionBlueprint $table) => $table->extensionCommand(),
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintWrapsFrameworkCommandsOverriddenByAnExtensionGrammar(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteBuilderExtensionGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->twice()->andReturn($grammar);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('overridden alter')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        $blueprint = new SQLiteBuilderExtensionBlueprint(
            $connection,
            'users',
            fn (SQLiteBuilderExtensionBlueprint $table) => $table->frameworkAlter(),
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintRestoresForeignKeysWhenTheTransactionFails(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'failing statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 0')->andReturn(0)->ordered();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback())
            ->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with('failing statement')
            ->andThrow(new LogicException('statement failed'))
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 1')->andReturn(0)->ordered();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('statement failed');

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintMarksTheSessionUnknownWhenForeignKeyRestorationFails(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 0')->andReturn(0)->ordered();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback())
            ->ordered();
        $connection->shouldReceive('statement')->once()->with('statement')->andReturnTrue()->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma foreign_keys = 1')->andReturnFalse()->ordered();
        $connection->shouldReceive('markCurrentSessionStateUnknown')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [pragma foreign_keys = 1].');

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintRejectsAPopulatedRebuildInsideATransactionWithForeignKeysEnabled(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        $blueprint->shouldReceive('getTable')->twice()->andReturn('users');
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select exists (select 1 from "users" limit 1)')
            ->andReturn(1);
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SQLite cannot rebuild the populated table [users] while foreign key constraints are enabled within an active transaction.'
        );

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintUsesASavepointForAnEmptyRebuildInsideATransaction(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        $blueprint->shouldReceive('getTable')->once()->andReturn('users');
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select exists (select 1 from "users" limit 1)')
            ->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintUsesASavepointInsideATransactionWhenForeignKeysAreDisabled(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('scalar')->never();
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotMutateSessionOrTransactionStateWhilePretending(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['pragma foreign_keys = 0', 'first statement', 'second statement', 'pragma foreign_keys = 1'],
            [new Fluent(['name' => 'alter'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('pretending')->once()->andReturnTrue();
        $connection->shouldReceive('transactionLevel')->never();
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('getPdo')->never();
        $connection->shouldReceive('statement')->once()->with('pragma foreign_keys = 0')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('pragma foreign_keys = 1')->andReturnTrue()->ordered();

        (new SQLiteBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testChangingForeignKeyConstraintsInsideATransactionFailsBeforeExecutingThePragma(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SQLite foreign key constraints cannot be enabled or disabled within an active transaction.'
        );

        (new SQLiteBuilder($connection))->disableForeignKeyConstraints();
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
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0)->ordered();
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(0)->ordered();
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0')->ordered();
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileRebuild('main'))
            ->andReturnTrue()
            ->ordered();

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllViewsRestoresAnEnabledWritableSchema(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0)->ordered();
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(1)->ordered();
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0')->ordered();
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllViews('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileRebuild('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();

        (new SQLiteBuilder($connection))->dropAllViews();
    }

    public function testDropAllTablesReloadsTheSchemaAfterADeleteFailure(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(0);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables('main'))
            ->andReturnFalse()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')->with($grammar->compileRebuild('main'))->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Failed to execute schema statement [delete from "main".sqlite_master where type in (\'table\', \'index\', \'trigger\')].'
        );

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesMarksALegacySessionUnknownWhenVacuumFails(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(0);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.36.0');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 0')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileRebuild('main'))
            ->andReturnFalse()
            ->ordered();
        $connection->shouldReceive('markCurrentSessionStateUnknown')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [vacuum "main"].');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesKeepsAModernSessionKnownWhenVacuumFailsAfterReset(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(0);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileRebuild('main'))
            ->andReturnFalse()
            ->ordered();
        $connection->shouldReceive('markCurrentSessionStateUnknown')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [vacuum "main"].');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllTablesMarksTheSessionUnknownWhenSchemaReloadFails(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(0);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturnFalse()->ordered();
        $connection->shouldReceive('markCurrentSessionStateUnknown')->once();
        $connection->shouldReceive('statement')->with($grammar->compileRebuild('main'))->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [pragma writable_schema = RESET].');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllViewsMarksTheSessionUnknownWhenWritableModeRestorationFails(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new SQLiteGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('scalar')->once()->with('pragma writable_schema')->andReturn(1);
        $connection->shouldReceive('getServerVersion')->once()->andReturn('3.45.0');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllViews('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = RESET')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileRebuild('main'))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('pragma writable_schema = 1')->andReturnFalse()->ordered();
        $connection->shouldReceive('markCurrentSessionStateUnknown')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute schema statement [pragma writable_schema = 1].');

        (new SQLiteBuilder($connection))->dropAllViews();
    }

    public function testDropAllTablesRejectsAnActiveTransactionBeforeInspectingTheSchema(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('scalar')->never();
        $connection->shouldReceive('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite cannot drop all tables within an active transaction.');

        (new SQLiteBuilder($connection))->dropAllTables();
    }

    public function testDropAllViewsRejectsAnActiveTransactionBeforeInspectingTheSchema(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('scalar')->never();
        $connection->shouldReceive('statement')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite cannot drop all views within an active transaction.');

        (new SQLiteBuilder($connection))->dropAllViews();
    }

    public function testRefreshDatabaseFileUsesTheCanonicalMainDatabasePath(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('file:database.sqlite?mode=rwc');
        $connection->shouldReceive('scalar')->once()->with('pragma journal_mode')->andReturn('delete');

        $builder = m::mock(SQLiteBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getSchemas')->once()->andReturn([
            ['name' => 'main', 'path' => '/canonical/database.sqlite'],
        ]);

        File::shouldReceive('put')->once()->with('/canonical/database.sqlite', '')->andReturn(0);

        $builder->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileRejectsWalForTheConnectedDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('/database.sqlite');
        $connection->shouldReceive('scalar')->once()->with('pragma journal_mode')->andReturn('wal');
        $connection->shouldReceive('selectFromWriteConnection')->never();
        File::shouldReceive('put')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SQLite database files cannot be refreshed through a connection using WAL journal mode. Use dropAllTables() to empty a database while connections are using it.'
        );

        (new SQLiteBuilder($connection))->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileRejectsAnActiveTransactionBeforeInspectingTheDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('getDatabaseName')->never();
        $connection->shouldReceive('scalar')->never();
        File::shouldReceive('put')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite cannot refresh the database file within an active transaction.');

        (new SQLiteBuilder($connection))->refreshDatabaseFile();
    }

    public function testRefreshDatabaseFileWithAnExplicitPathDoesNotInspectTheConnection(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->never();
        $connection->shouldReceive('getDatabaseName')->never();
        $connection->shouldReceive('scalar')->never();
        $connection->shouldReceive('selectFromWriteConnection')->never();
        File::shouldReceive('put')->once()->with('/database.sqlite', '')->andReturn(0);

        (new SQLiteBuilder($connection))->refreshDatabaseFile('/database.sqlite');
    }

    #[DataProvider('inMemoryDatabaseNames')]
    public function testRefreshDatabaseFileRejectsInMemoryDatabasesWithoutWritingAFile(string $database): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn($database);
        $connection->shouldReceive('scalar')->never();
        File::shouldReceive('put')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
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
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(new SQLiteGrammar($connection));

        File::shouldReceive('put')
            ->once()
            ->with('/database.sqlite', '')
            ->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to refresh SQLite database file [/database.sqlite].');

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
        $blueprint = m::mock(Blueprint::class);
        $blueprint->shouldReceive('toSql')->once()->andReturn($statements);
        $blueprint->shouldReceive('getCommands')->andReturn($commands);

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
