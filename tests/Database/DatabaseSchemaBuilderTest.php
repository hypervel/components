<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\MultipleColumnsSelectedException;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseSchemaBuilderTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('metadataMethods')]
    public function testMetadataReadsUseTheOverridableWriterHook(string $method, array $arguments, string $compile, array $compileArguments, string $process): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $processor = Double::for(Processor::class);
        $rows = [(object) ['name' => 'id']];
        $processed = [['name' => 'id']];
        $connection->allows('getSchemaGrammar')->returns($grammar);
        $connection->allows('getPostProcessor')->returns($processor);
        $connection->allows('getTablePrefix')->returns('prefix_');
        $grammar->expects($compile)->with(...$compileArguments)->returns('metadata sql');
        $connection->expects('selectFromWriteConnection')->with('metadata sql')->returns($rows);
        $processor->expects($process)->with($rows)->returns($processed);
        $builder = new DatabaseSchemaMetadataBuilder($connection);

        $this->assertSame($method === 'hasColumn' ? true : $processed, $builder->{$method}(...$arguments));
        $this->assertSame(['metadata sql'], $builder->metadataQueries);
    }

    public static function metadataMethods(): array
    {
        return [
            'schemas' => ['getSchemas', [], 'compileSchemas', [], 'processSchemas'],
            'tables' => ['getTables', ['public'], 'compileTables', ['public'], 'processTables'],
            'views' => ['getViews', ['public'], 'compileViews', ['public'], 'processViews'],
            'types' => ['getTypes', ['public'], 'compileTypes', ['public'], 'processTypes'],
            'columns' => ['getColumns', ['public.users'], 'compileColumns', ['public', 'prefix_users'], 'processColumns'],
            'indexes' => ['getIndexes', ['public.users'], 'compileIndexes', ['public', 'prefix_users'], 'processIndexes'],
            'foreign keys' => ['getForeignKeys', ['public.users'], 'compileForeignKeys', ['public', 'prefix_users'], 'processForeignKeys'],
            'partitions' => ['getPartitions', ['public.users'], 'compilePartitions', ['public', 'prefix_users'], 'processPartitions'],
            'derived column check' => ['hasColumn', ['public.users', 'ID'], 'compileColumns', ['public', 'prefix_users'], 'processColumns'],
        ];
    }

    #[DataProvider('tableExistenceResults')]
    public function testTableExistenceUsesTheMetadataHookAndPreservesScalarValidation(array $rows, ?bool $expected): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $connection->allows('getSchemaGrammar')->returns($grammar);
        $connection->allows('getTablePrefix')->returns('prefix_');
        $grammar->expects('compileTableExists')->with('public', 'prefix_users')->returns('exists sql');
        $connection->expects('selectFromWriteConnection')->with('exists sql')->returns($rows);
        $builder = new DatabaseSchemaMetadataBuilder($connection);

        if ($expected === null) {
            $this->expectException(MultipleColumnsSelectedException::class);
        }

        try {
            $this->assertSame($expected, $builder->hasTable('public.users'));
        } finally {
            $this->assertSame(['exists sql'], $builder->metadataQueries);
        }
    }

    public static function tableExistenceResults(): array
    {
        return [
            'true object' => [[(object) ['exists' => 1]], true],
            'false object' => [[(object) ['exists' => 0]], false],
            'true array' => [[['exists' => 1]], true],
            'first row only' => [[['exists' => 0], ['exists' => 1]], false],
            'empty' => [[], false],
            'null value' => [[['exists' => null]], false],
            'invalid columns' => [[(object) ['exists' => 1, 'unexpected' => 2]], null],
        ];
    }

    public function testCreateDatabase(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with($grammar->compileCreateDatabase('foo'))->returns(true);
        $builder = new Builder($connection);

        $this->assertTrue($builder->createDatabase('foo'));
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with($grammar->compileDropDatabaseIfExists('foo'))->returns(true);
        $builder = new Builder($connection);

        $this->assertTrue($builder->dropDatabaseIfExists('foo'));
    }

    public function testTruncateTablesChecksWriteRowsAndSkipsEmptyTables(): void
    {
        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaGrammar')->returns(Double::for(Grammar::class));

        foreach (['public.populated' => true, 'public.empty' => false] as $name => $hasRows) {
            $query = Double::for(QueryBuilder::class);
            $connection->expects('table')->with($name)->returns($query);
            $query->expects('useWritePdo')->returns($query);
            $query->expects('exists')->returns($hasRows);

            if ($hasRows) {
                $query->expects('truncate');
            } else {
                $query->expects('truncate')->never();
            }
        }

        (new Builder($connection))->truncateTables(['public.populated', 'public.empty']);
    }

    #[DataProvider('partitionSelections')]
    public function testWithoutCoveredPartitionsKeepsOnlyPartitionsOfTablesOutsideTheSelection(array $tables, array $except, ?array $missingParents, array $links, array $expected): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $connection->allows('getSchemaGrammar')->returns($grammar);

        if ($missingParents === null) {
            $grammar->expects('compilePartitionAncestors')->never();
        } else {
            $grammar->expects('compilePartitionAncestors')->with($missingParents)->returns('ancestors sql');
            $connection->expects('selectFromWriteConnection')->with('ancestors sql')->returns($links);
        }

        $tables = array_map(fn (array $table): array => ['schema_qualified_name' => $table[0], 'partition_of' => $table[1]], $tables);

        $this->assertSame($expected, array_column((new Builder($connection))->withoutCoveredPartitions($tables, $except), 'schema_qualified_name'));
    }

    public static function partitionSelections(): array
    {
        return [
            'given parents cover their partitions, excepted or not' => [
                [['public.events', null], ['public.events_1', 'public.events'], ['archive.events', null], ['archive.events_1', 'archive.events']],
                ['public.events'],
                null,
                [],
                ['public.events', 'archive.events'],
            ],
            'an excepted parent outside the selection keeps its partition' => [
                [['archive.events_oct', 'public.events'], ['archive.cold_oct', 'cold.events']],
                ['public.events'],
                ['public.events', 'cold.events'],
                [],
                ['archive.cold_oct'],
            ],
            'an excepted root above an intermediate outside the selection keeps the leaf' => [
                [['archive.events_oct', 'cold.events_2026'], ['archive.other_oct', 'cold.other_2026']],
                ['events'],
                ['cold.events_2026', 'cold.other_2026'],
                [
                    (object) ['name' => 'cold.events_2026', 'partition_of' => 'public.events'],
                    (object) ['name' => 'cold.other_2026', 'partition_of' => 'public.other'],
                ],
                ['archive.other_oct'],
            ],
        ];
    }

    public function testExecuteBlueprintCompilesOnceAndExecutesStatementsInOrder(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $blueprint = Double::for(Blueprint::class);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $blueprint->expects('toSql')->returns(['first statement', 'second statement']);
        $connection->expects('statement')->with('first statement')->returns(true)->ordered();
        $connection->expects('statement')->with('second statement')->returns(true)->ordered();

        (new Builder($connection))->executeBlueprint($blueprint);
    }

    public function testExecuteBlueprintThrowsWhenAStatementReturnsFalse(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $blueprint = Double::for(Blueprint::class);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $blueprint->expects('toSql')->returns(['failed statement', 'unreached statement']);
        $connection->expects('statement')->with('failed statement')->returns(false);
        $connection->expects('statement')->never()->with('unreached statement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Failed to execute schema statement [failed statement].');

        (new Builder($connection))->executeBlueprint($blueprint);
    }

    public function testWithoutForeignKeyConstraintsNestsAcrossBuilderInstances(): void
    {
        $pdo = Double::for(PDO::class);
        $connection = new PdoConnection($pdo, 'test');
        $grammar = Double::for(Grammar::class);
        $connection->setSchemaGrammar($grammar);

        $grammar->expects('compileDisableForeignKeyConstraints')->returns('disable constraints');
        $grammar->expects('compileEnableForeignKeyConstraints')->returns('enable constraints');
        $pdo->expects('exec')->with('disable constraints')->returns(0)->ordered();
        $pdo->expects('exec')->with('enable constraints')->returns(0)->ordered();

        $outer = new Builder($connection);
        $inner = new Builder($connection);

        $result = $outer->withoutForeignKeyConstraints(
            fn () => $inner->withoutForeignKeyConstraints(fn () => 'result')
        );

        $this->assertSame('result', $result);
    }

    public function testWithoutForeignKeyConstraintsUsesTheStatementPathWhilePretending(): void
    {
        $resolutions = 0;
        $connection = new PdoConnection(
            static function () use (&$resolutions): PDO {
                ++$resolutions;

                return new PDO('sqlite::memory:');
            },
            'test'
        );
        $grammar = Double::for(Grammar::class);
        $connection->setSchemaGrammar($grammar);

        $grammar->expects('compileDisableForeignKeyConstraints')->returns('disable constraints');
        $grammar->expects('compileEnableForeignKeyConstraints')->returns('enable constraints');

        $queries = $connection->pretend(
            fn () => (new Builder($connection))->withoutForeignKeyConstraints(fn () => null)
        );

        $this->assertSame(0, $resolutions);
        $this->assertSame(
            ['disable constraints', 'enable constraints'],
            array_column($queries, 'query')
        );
    }

    public function testWithoutForeignKeyConstraintsMarksTheSessionUnknownWhenRestorationFails(): void
    {
        $pdo = Double::for(PDO::class);
        $connection = new PdoConnection($pdo, 'test');
        $grammar = Double::for(Grammar::class);
        $connection->setSchemaGrammar($grammar);
        $failure = new PDOException('Failed to restore foreign key constraints.');

        $grammar->expects('compileDisableForeignKeyConstraints')->returns('disable constraints');
        $grammar->expects('compileEnableForeignKeyConstraints')->returns('enable constraints');
        $pdo->expects('exec')->with('disable constraints')->returns(0)->ordered();
        $pdo->expects('exec')->with('enable constraints')->throws($failure)->ordered();

        try {
            (new Builder($connection))->withoutForeignKeyConstraints(fn () => null);
            $this->fail('Expected foreign key constraint restoration to fail.');
        } catch (PDOException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse($connection->isReusable());
        $this->assertTrue($connection->beginForeignKeyConstraintSuppression());

        $connection->endForeignKeyConstraintSuppression();
    }

    public function testWithoutForeignKeyConstraintsPreservesNativeExceptionChainingWhenCallbackAndRestorationFail(): void
    {
        $pdo = Double::for(PDO::class);
        $connection = new PdoConnection($pdo, 'test');
        $grammar = Double::for(Grammar::class);
        $connection->setSchemaGrammar($grammar);
        $callbackFailure = new RuntimeException('callback failed');
        $restorationFailure = new RuntimeException('restoration failed');

        $grammar->expects('compileDisableForeignKeyConstraints')->returns('disable constraints');
        $grammar->expects('compileEnableForeignKeyConstraints')->returns('enable constraints');
        $pdo->expects('exec')->with('disable constraints')->returns(0)->ordered();
        $pdo->expects('exec')->with('enable constraints')->throws($restorationFailure)->ordered();

        try {
            (new Builder($connection))->withoutForeignKeyConstraints(
                static fn () => throw $callbackFailure
            );
            $this->fail('Expected foreign key constraint restoration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($restorationFailure, $exception);
            $this->assertSame($callbackFailure, $exception->getPrevious());
        }

        $this->assertFalse($connection->isReusable());
    }

    public function testHasTableCorrectlyCallsGrammar(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $processor = Double::for(Processor::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $builder = new Builder($connection);
        $grammar->expects('compileTableExists');
        $grammar->expects('compileTables')->returns('sql');
        $processor->expects('processTables')->returns([['name' => 'prefix_table']]);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with('sql')->returns([['name' => 'prefix_table']]);

        $this->assertTrue($builder->hasTable('table'));
    }

    public function testTableHasColumns(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = Double::for(Grammar::class);
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new Builder($connection))->passthru();
        $builder->expects('getColumnListing')->with('users')->times(2)->returns(['id', 'firstname']);

        $this->assertTrue($builder->hasColumns('users', ['id', 'firstname']));
        $this->assertFalse($builder->hasColumns('users', ['id', 'address']));
    }

    #[DataProvider('columnNames')]
    public function testColumnExistenceMatchesUnicodeWithoutCoercingNumericNames(string $column, bool $exists): void
    {
        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaGrammar')->returns(Double::for(Grammar::class));
        $builder = Double::for(new Builder($connection))->passthru();
        $builder->expects('getColumnListing')->with('users')->times(2)->returns(['Émail', '01']);

        $this->assertSame($exists, $builder->hasColumn('users', $column));
        $this->assertSame($exists, $builder->hasColumns('users', [$column]));
    }

    /**
     * Provide column names and their expected existence.
     */
    public static function columnNames(): array
    {
        return [
            ['émail', true],
            ['01', true],
            ['1', false],
        ];
    }

    #[DataProvider('columnLookups')]
    public function testColumnLookupPrefersExactNamesBeforeCaseInsensitiveMatches(string $column, int $index): void
    {
        $columns = [
            ['name' => 'foo', 'type_name' => 'integer', 'type' => 'integer'],
            ['name' => 'FOO', 'type_name' => 'varchar', 'type' => 'varchar(255)'],
            ['name' => 'Émail', 'type_name' => 'text', 'type' => 'text'],
        ];
        $connection = Double::for(Connection::class);
        $connection->expects('getSchemaGrammar')->returns(Double::for(Grammar::class));
        $builder = Double::for(new Builder($connection))->passthru();
        $builder->expects('getColumns')->with('users')->times(3)->returns($columns);

        $this->assertSame($columns[$index], $builder->getColumn('users', $column));
        $this->assertSame($columns[$index]['type_name'], $builder->getColumnType('users', $column));
        $this->assertSame($columns[$index]['type'], $builder->getColumnType('users', $column, true));
    }

    /**
     * Provide exact and case-insensitive column lookups.
     */
    public static function columnLookups(): array
    {
        return [
            ['foo', 0],
            ['FOO', 1],
            ['fOo', 0],
            ['émail', 2],
        ];
    }

    public function testGetColumnTypeAddsPrefix(): void
    {
        $connection = Double::for(Connection::class);
        $grammar = new class($connection) extends Grammar {
            /**
             * Compile the query to determine the columns.
             */
            public function compileColumns(?string $schema, string $table): string
            {
                return "columns for {$table}";
            }
        };
        $processor = new Processor;
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('getPostProcessor')->returns($processor);
        $builder = new Builder($connection);
        $connection->expects('getTablePrefix')->returns('prefix_');
        $connection->expects('selectFromWriteConnection')->with('columns for prefix_users')->returns([['name' => 'id', 'type_name' => 'integer']]);

        $this->assertSame('integer', $builder->getColumnType('users', 'id'));
    }
}

class DatabaseSchemaMetadataBuilder extends Builder
{
    public array $metadataQueries = [];

    protected function selectMetadata(string $query): array
    {
        $this->metadataQueries[] = $query;

        return parent::selectMetadata($query);
    }
}
