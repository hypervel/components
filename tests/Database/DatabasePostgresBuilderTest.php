<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Grammars\PostgresGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Support\Fluent;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Override;
use RuntimeException;

class DatabasePostgresBuilderTest extends TestCase
{
    public function testCreateDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getConfig')->with('charset')->andReturn('utf8');
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'create database "my_temporary_database" encoding "utf8"'
        )->andReturn(true);

        $builder = $this->getBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'drop database if exists "my_database_a"'
        )->andReturn(true);

        $builder = $this->getBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }

    public function testExecuteBlueprintWrapsKnownMultiStatementCommandsInATransaction(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotNestAnExistingTransaction(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapASingleStatement(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->never();
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('statement')->andReturnTrue();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapOnlineCommands(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['create index concurrently', 'attach constraint'],
            [new Fluent(['name' => 'unique', 'online' => true])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('create index concurrently')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('attach constraint')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapCommandsAddedByAnExtensionGrammar(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['extension statement one', 'extension statement two'],
            [new Fluent(['name' => 'extensionCommand'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('extension statement one')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('extension statement two')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintWrapsFrameworkCommandsOverriddenByAnExtensionGrammar(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new PostgresBuilderExtensionGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['overridden create', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (Closure $callback) => $callback());
        $connection->shouldReceive('statement')->once()->with('overridden create')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintHonorsTheRuntimeGrammarTransactionFlag(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(PostgresGrammar::class, [$connection])->makePartial();
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $grammar->shouldReceive('supportsSchemaTransactions')->once()->andReturnFalse();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->never();
        $connection->shouldReceive('statement')->once()->with('first statement')->andReturnTrue()->ordered();
        $connection->shouldReceive('statement')->once()->with('second statement')->andReturnTrue()->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathMissing(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileTableExists')->times(2)->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->andReturn([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('public.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileTableExists')->times(2)->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->andReturn([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathFallbackFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileTableExists')->times(2)->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->andReturn([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathIsUserVariable(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileTableExists')->times(2)->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->andReturn([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('foouser.foo'));
    }

    public function testHasTableWhenSchemaQualifiedAndSearchPathMismatches(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileTableExists')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['exists' => 1]]);
        $connection->expects('getTablePrefix');
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenDatabaseAndSchemaQualifiedAndSearchPathMismatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = $this->getBuilder($connection);

        $builder->hasTable('mydatabase.myapp.foo');
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathMissing(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileColumns')->with(null, 'foo')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processColumns')->andReturn([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('foo');
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileColumns')->with(null, 'foo')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processColumns')->andReturn([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('foo');
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathIsUserVariable(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileColumns')->with(null, 'foo')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processColumns')->andReturn([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('foo');
    }

    public function testGetColumnListingWhenSchemaQualifiedAndSearchPathMismatches(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $grammar->expects('compileColumns')->with('myapp', 'foo')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processColumns')->andReturn([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('myapp.foo');
    }

    public function testGetColumnWhenDatabaseAndSchemaQualifiedAndSearchPathMismatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('mydatabase.myapp.foo');
    }

    public function testCurrentSchemaNameQueriesTheConnectionRatherThanConfiguredSearchPathOrder(): void
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->once()->with('username')->andReturn('foouser');
        $connection->shouldReceive('getConfig')->once()->with('search_path')->andReturn('"$user", public');
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn(m::mock(PostgresGrammar::class));
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select current_schema()', [], false)
            ->andReturn('public');

        $builder = $this->getBuilder($connection);

        $this->assertSame(['foouser', 'public'], $builder->getCurrentSchemaListing());
        $this->assertSame('public', $builder->getCurrentSchemaName());
    }

    public function testHasViewUsesTheActualCurrentSchema(): void
    {
        $connection = $this->getConnection();
        $grammar = m::mock(PostgresGrammar::class);
        $processor = m::mock(PostgresProcessor::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('');
        $connection->shouldReceive('scalar')
            ->once()
            ->with('select current_schema()', [], false)
            ->andReturn('public');
        $grammar->shouldReceive('compileViews')->once()->with('public')->andReturn('sql');
        $connection->shouldReceive('selectFromWriteConnection')->once()->with('sql')->andReturn([
            ['name' => 'active_users'],
        ]);
        $connection->shouldReceive('getPostProcessor')->once()->andReturn($processor);
        $processor->shouldReceive('processViews')->once()->andReturn([
            ['name' => 'active_users'],
        ]);

        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasView('active_users'));
    }

    public function testDropAllTablesWhenSearchPathIsString(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('search_path')->andReturn('public');
        $connection->expects('getConfig')->with('dont_drop')->andReturn(['foo']);
        $grammar = m::mock(PostgresGrammar::class);
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $grammar->expects('compileTables')->andReturn('sql');
        $processor->expects('processTables')->andReturn([['name' => 'users', 'schema' => 'public', 'schema_qualified_name' => 'public.users']]);
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'users', 'schema' => 'public', 'schema_qualified_name' => 'public.users']]);
        $grammar->expects('compileDropAllTables')->with(['public.users'])->andReturn('drop table "public"."users" cascade');
        $connection->expects('statement')->with('drop table "public"."users" cascade')->andReturnTrue();
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllTablesWhenSearchPathIsStringOfMany(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('username')->andReturn('foouser');
        $connection->expects('getConfig')->with('search_path')->andReturn('"$user", public, foo_bar-Baz.Áüõß');
        $connection->expects('getConfig')->with('dont_drop')->andReturn(['foo']);
        $grammar = m::mock(PostgresGrammar::class);
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processTables')->andReturn([['name' => 'users', 'schema' => 'foouser', 'schema_qualified_name' => 'foouser.users']]);
        $grammar->expects('compileTables')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'users', 'schema' => 'foouser', 'schema_qualified_name' => 'foouser.users']]);
        $grammar->expects('compileDropAllTables')->with(['foouser.users'])->andReturn('drop table "foouser"."users" cascade');
        $connection->expects('statement')->with('drop table "foouser"."users" cascade')->andReturnTrue();
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllTablesWhenSearchPathIsArrayOfMany(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('username')->andReturn('foouser');
        $connection->expects('getConfig')->with('search_path')->andReturn([
            '$user',
            '"dev"',
            "'test'",
            'spaced schema',
        ]);
        $connection->expects('getConfig')->with('dont_drop')->andReturn(['foo']);
        $grammar = m::mock(PostgresGrammar::class);
        $processor = m::mock(PostgresProcessor::class);
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('getPostProcessor')->andReturn($processor);
        $processor->expects('processTables')->andReturn([['name' => 'users', 'schema' => 'foouser', 'schema_qualified_name' => 'foouser.users']]);
        $grammar->expects('compileTables')->andReturn('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->andReturn([['name' => 'users', 'schema' => 'foouser', 'schema_qualified_name' => 'foouser.users']]);
        $grammar->expects('compileDropAllTables')->with(['foouser.users'])->andReturn('drop table "foouser"."users" cascade');
        $connection->expects('statement')->with('drop table "foouser"."users" cascade')->andReturnTrue();
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllTablesPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('getConfig')->once()->with('dont_drop')->andReturn([]);
        $builder = m::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['public']);
        $builder->shouldReceive('getTables')->once()->with(['public'])->andReturn([
            ['name' => 'users', 'schema_qualified_name' => 'public.users'],
        ]);
        $statement = $grammar->compileDropAllTables(['public.users']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTables();
    }

    public function testDropAllViewsPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['public']);
        $builder->shouldReceive('getViews')->once()->with(['public'])->andReturn([
            ['schema_qualified_name' => 'public.active_users'],
        ]);
        $statement = $grammar->compileDropAllViews(['public.active_users']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllViews();
    }

    public function testDropAllTypesPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['public']);
        $builder->shouldReceive('getTypes')->once()->with(['public'])->andReturn([
            ['implicit' => false, 'type' => 'enum', 'schema_qualified_name' => 'public.status'],
        ]);
        $statement = $grammar->compileDropAllTypes(['public.status']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTypes();
    }

    public function testDropAllDomainsPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(PostgresBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['public']);
        $builder->shouldReceive('getTypes')->once()->with(['public'])->andReturn([
            ['implicit' => false, 'type' => 'domain', 'schema_qualified_name' => 'public.email'],
        ]);
        $statement = $grammar->compileDropAllDomains(['public.email']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTypes();
    }

    /**
     * Create a database connection mock.
     */
    protected function getConnection(): Connection
    {
        return m::mock(Connection::class);
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
     * Create the PostgreSQL schema builder.
     */
    protected function getBuilder(Connection $connection): PostgresBuilder
    {
        return new PostgresBuilder($connection);
    }
}

class PostgresBuilderExtensionGrammar extends PostgresGrammar
{
    /**
     * Compile a create table command.
     */
    #[Override]
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        return 'overridden create';
    }
}
