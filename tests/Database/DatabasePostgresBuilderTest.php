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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabasePostgresBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateDatabase(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getConfig')->with('charset')->returns('utf8');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'create database "my_temporary_database" encoding "utf8"'
        )->returns(true);

        $builder = $this->getBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'drop database if exists "my_database_a"'
        )->returns(true);

        $builder = $this->getBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }

    public function testExecuteBlueprintWrapsKnownMultiStatementCommandsInATransaction(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotNestAnExistingTransaction(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(1);
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapASingleStatement(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->never();
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('statement')->returns(true);

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapOnlineCommands(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['create index concurrently', 'attach constraint'],
            [new Fluent(['name' => 'unique', 'online' => true])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('create index concurrently')->returns(true)->ordered();
        $connection->expects('statement')->with('attach constraint')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintDoesNotWrapCommandsAddedByAnExtensionGrammar(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['extension statement one', 'extension statement two'],
            [new Fluent(['name' => 'extensionCommand'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('extension statement one')->returns(true)->ordered();
        $connection->expects('statement')->with('extension statement two')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintWrapsFrameworkCommandsOverriddenByAnExtensionGrammar(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new PostgresBuilderExtensionGrammar($connection);
        $blueprint = $this->executionBlueprint(
            ['overridden create', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')
            ->resolves(static fn (Closure $callback) => $callback());
        $connection->expects('statement')->with('overridden create')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintHonorsTheRuntimeGrammarTransactionFlag(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(new PostgresGrammar($connection))->passthru();
        $blueprint = $this->executionBlueprint(
            ['first statement', 'second statement'],
            [new Fluent(['name' => 'create'])],
        );

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $grammar->expects('supportsSchemaTransactions')->returns(false);
        $connection->expects('transactionLevel')->returns(0);
        $connection->expects('transaction')->never();
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new PostgresBuilder($connection))->executeBlueprint($blueprint);
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathMissing(): void
    {
        $connection = $this->getConnection();
        $grammar = Double::for(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $grammar->expects('compileTableExists')->times(2)->returns('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->returns([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('public.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = Double::for(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $grammar->expects('compileTableExists')->times(2)->returns('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->returns([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathFallbackFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = Double::for(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $grammar->expects('compileTableExists')->times(2)->returns('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->returns([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenSchemaUnqualifiedAndSearchPathIsUserVariable(): void
    {
        $connection = $this->getConnection();
        $grammar = Double::for(PostgresGrammar::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $grammar->expects('compileTableExists')->times(2)->returns('sql');
        $connection->expects('selectFromWriteConnection')->times(2)->with('sql')->returns([['exists' => 1]]);
        $connection->expects('getTablePrefix')->times(2);
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('foo'));
        $this->assertTrue($builder->hasTable('foouser.foo'));
    }

    public function testHasTableWhenSchemaQualifiedAndSearchPathMismatches(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTableExists('myapp', 'foo'))->returns([['exists' => 1]]);
        $connection->expects('getTablePrefix');
        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasTable('myapp.foo'));
    }

    public function testHasTableWhenDatabaseAndSchemaQualifiedAndSearchPathMismatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = $this->getBuilder($connection);

        $builder->hasTable('mydatabase.myapp.foo');
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathMissing(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->allows('getServerVersion')->returns('12.0.0');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'foo'))->returns([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = Double::for(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->returns($processor);
        $processor->expects('processColumns')->returns([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $this->assertSame(['some_column'], $builder->getColumnListing('foo'));
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathFilled(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->allows('getServerVersion')->returns('12.0.0');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'foo'))->returns([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = Double::for(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->returns($processor);
        $processor->expects('processColumns')->returns([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $this->assertSame(['some_column'], $builder->getColumnListing('foo'));
    }

    public function testGetColumnListingWhenSchemaUnqualifiedAndSearchPathIsUserVariable(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->allows('getServerVersion')->returns('12.0.0');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns(null, 'foo'))->returns([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = Double::for(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->returns($processor);
        $processor->expects('processColumns')->returns([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $this->assertSame(['some_column'], $builder->getColumnListing('foo'));
    }

    public function testGetColumnListingWhenSchemaQualifiedAndSearchPathMismatches(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->allows('getServerVersion')->returns('12.0.0');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileColumns('myapp', 'foo'))->returns([['name' => 'some_column']]);
        $connection->expects('getTablePrefix');
        $processor = Double::for(PostgresProcessor::class);
        $connection->expects('getPostProcessor')->returns($processor);
        $processor->expects('processColumns')->returns([['name' => 'some_column']]);
        $builder = $this->getBuilder($connection);

        $this->assertSame(['some_column'], $builder->getColumnListing('myapp.foo'));
    }

    public function testGetColumnWhenDatabaseAndSchemaQualifiedAndSearchPathMismatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = $this->getBuilder($connection);

        $builder->getColumnListing('mydatabase.myapp.foo');
    }

    public function testCurrentSchemaNameQueriesTheConnectionRatherThanConfiguredSearchPathOrder(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('username')->returns('foouser');
        $connection->expects('getConfig')->with('search_path')->returns('"$user", public');
        $connection->expects('getSchemaGrammar')->returns(Double::for(PostgresGrammar::class));
        $connection->expects('scalar')
            ->with('select current_schema()', [], false)
            ->returns('public');

        $builder = $this->getBuilder($connection);

        $this->assertSame(['foouser', 'public'], $builder->getCurrentSchemaListing());
        $this->assertSame('public', $builder->getCurrentSchemaName());
    }

    #[DataProvider('currentSchemaListingConfigurationProvider')]
    public function testCurrentSchemaListingFollowsTheConnectorSettingPrecedence(?string $searchPath, ?string $schema, array $expected): void
    {
        $connection = $this->getConnection();
        $connection->expects('getSchemaGrammar')->returns(Double::for(PostgresGrammar::class));
        $connection->allows('getConfig')->with('search_path')->returns($searchPath);
        $connection->allows('getConfig')->with('schema')->returns($schema);

        $this->assertSame($expected, $this->getBuilder($connection)->getCurrentSchemaListing());
    }

    /**
     * Get the search path and schema settings with their expected schema listings.
     */
    public static function currentSchemaListingConfigurationProvider(): array
    {
        return [
            'search path named zero' => ['0', 'tenant', ['0']],
            'schema fallback' => [null, 'tenant', ['tenant']],
            'public default' => [null, null, ['public']],
            'empty search path' => ['', 'tenant', []],
        ];
    }

    public function testHasViewIsFalseWithoutACurrentSchema(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getSchemaGrammar')->returns(Double::for(PostgresGrammar::class));
        $connection->expects('scalar')
            ->with('select current_schema()', [], false)
            ->returns(null);
        $connection->expects('selectFromWriteConnection')->never();

        $this->assertFalse($this->getBuilder($connection)->hasView('active_users'));
    }

    public function testHasViewUsesTheActualCurrentSchema(): void
    {
        $connection = $this->getConnection();
        $grammar = Double::for(PostgresGrammar::class);
        $processor = Double::for(PostgresProcessor::class);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getTablePrefix')->returns('');
        $connection->expects('scalar')
            ->with('select current_schema()', [], false)
            ->returns('tenant.example');
        $grammar->expects('compileViews')->with('tenant.example')->returns('sql');
        $connection->expects('selectFromWriteConnection')->with('sql')->returns([
            ['name' => 'active_users'],
        ]);
        $connection->expects('getPostProcessor')->returns($processor);
        $processor->expects('processViews')->returns([
            ['name' => 'active_users'],
        ]);

        $builder = $this->getBuilder($connection);

        $this->assertTrue($builder->hasView('active_users'));
    }

    public function testDropAllTablesWhenSearchPathIsString(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('search_path')->returns('public');
        $connection->expects('getConfig')->with('dont_drop')->returns(['foo']);
        $grammar = new PostgresGrammar($connection);
        $processor = new PostgresProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $connection->expects('selectFromWriteConnection')->with($grammar->compileTables(['public']))->returns([
            (object) ['name' => 'foo', 'schema' => 'public', 'partition_of' => null],
            (object) ['name' => 'foo_1', 'schema' => 'public', 'partition_of' => 'public.foo'],
            (object) ['name' => 'users', 'schema' => 'public', 'partition_of' => null],
            (object) ['name' => 'users_1', 'schema' => 'public', 'partition_of' => 'public.users'],
        ]);
        $connection->expects('statement')->with('drop table "public"."users" cascade')->returns(true);
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllTablesWhenSearchPathIsStringOfMany(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('username')->returns('foouser');
        $connection->expects('getConfig')->with('search_path')->returns('"$user", public, foo_bar-Baz.Áüõß');
        $connection->expects('getConfig')->with('dont_drop')->returns(['foo']);
        $grammar = new PostgresGrammar($connection);
        $processor = new PostgresProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $connection->expects('selectFromWriteConnection')
            ->with($grammar->compileTables(['foouser', 'public', 'foo_bar-Baz.Áüõß']))
            ->returns([(object) ['name' => 'users', 'schema' => 'foouser']]);
        $connection->expects('statement')->with('drop table "foouser"."users" cascade')->returns(true);
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllTablesWhenSearchPathIsArrayOfMany(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getConfig')->with('username')->returns('foouser');
        $connection->expects('getConfig')->with('search_path')->returns([
            '$user',
            '"dev"',
            "'test'",
            'spaced schema',
        ]);
        $connection->expects('getConfig')->with('dont_drop')->returns(['foo']);
        $grammar = new PostgresGrammar($connection);
        $processor = new PostgresProcessor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $connection->expects('selectFromWriteConnection')
            ->with($grammar->compileTables(['foouser', 'dev', 'test', 'spaced schema']))
            ->returns([(object) ['name' => 'users', 'schema' => 'foouser']]);
        $connection->expects('statement')->with('drop table "foouser"."users" cascade')->returns(true);
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
    }

    public function testDropAllObjectsWithAnEmptySearchPathLeavesEverySchemaUntouched(): void
    {
        $connection = $this->getConnection();
        $connection->expects('getSchemaGrammar')->returns(Double::for(PostgresGrammar::class));
        $connection->expects('getConfig')->times(3)->with('search_path')->returns('');
        $connection->expects('selectFromWriteConnection')->never();
        $connection->expects('statement')->never();
        $builder = $this->getBuilder($connection);

        $builder->dropAllTables();
        $builder->dropAllViews();
        $builder->dropAllTypes();
    }

    public function testDropAllTablesPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getConfig')->with('dont_drop')->returns([]);
        $builder = Double::for(new PostgresBuilder($connection))->passthru();
        $builder->expects('getCurrentSchemaListing')->returns(['public']);
        $builder->expects('getTables')->with(['public'])->returns([
            ['name' => 'users', 'schema_qualified_name' => 'public.users', 'partition_of' => null],
        ]);
        $statement = $grammar->compileDropAllTables(['public.users']);
        $connection->expects('statement')->with($statement)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTables();
    }

    public function testDropAllViewsPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new PostgresBuilder($connection))->passthru();
        $builder->expects('getCurrentSchemaListing')->returns(['public']);
        $builder->expects('getViews')->with(['public'])->returns([
            ['schema_qualified_name' => 'public.active_users'],
        ]);
        $statement = $grammar->compileDropAllViews(['public.active_users']);
        $connection->expects('statement')->with($statement)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllViews();
    }

    public function testDropAllTypesPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new PostgresBuilder($connection))->passthru();
        $builder->expects('getCurrentSchemaListing')->returns(['public']);
        $builder->expects('getTypes')->with(['public'])->returns([
            ['implicit' => false, 'type' => 'enum', 'schema_qualified_name' => 'public.status'],
        ]);
        $statement = $grammar->compileDropAllTypes(['public.status']);
        $connection->expects('statement')->with($statement)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTypes();
    }

    public function testDropAllDomainsPropagatesAFalseStatementResult(): void
    {
        $connection = $this->getConnection();
        $grammar = new PostgresGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new PostgresBuilder($connection))->passthru();
        $builder->expects('getCurrentSchemaListing')->returns(['public']);
        $builder->expects('getTypes')->with(['public'])->returns([
            ['implicit' => false, 'type' => 'domain', 'schema_qualified_name' => 'public.email'],
        ]);
        $statement = $grammar->compileDropAllDomains(['public.email']);
        $connection->expects('statement')->with($statement)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTypes();
    }

    /**
     * Create a database connection mock.
     */
    protected function getConnection(): Connection
    {
        return Double::for(Connection::class);
    }

    /**
     * Create a Blueprint double for execution-boundary tests.
     *
     * @param list<string> $statements
     * @param list<Fluent> $commands
     */
    protected function executionBlueprint(array $statements, array $commands): Blueprint
    {
        $blueprint = Double::for(Blueprint::class);
        $blueprint->expects('toSql')->returns($statements);
        $blueprint->allows('getCommands')->returns($commands);

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
