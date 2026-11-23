<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\MultipleColumnsSelectedException;
use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSQLiteSchemaMetadataTest extends TestCase
{
    use VerifiesDoubles;

    #[DataProvider('tableListingVersions')]
    public function testBothTableListingPathsUseTheMetadataHook(string $version, string $compiler): void
    {
        [$builder, $connection, $grammar, $processor] = $this->builder();
        $connection->expects('getServerVersion')->returns($version);
        $grammar->expects('compileDbstatExists')->returns('dbstat sql');
        $connection->expects('scalar')->with('dbstat sql')->returns(1);
        $grammar->expects($compiler)->with('main', true)->returns('tables sql');
        $rows = [(object) ['name' => 'users']];
        $connection->expects('selectFromWriteConnection')->with('tables sql')->returns($rows);
        $processor->expects('processTables')->with($rows)->returns([['name' => 'users']]);

        $this->assertSame([['name' => 'users']], $builder->getTables('main'));
        $this->assertSame(['tables sql'], $builder->metadataQueries);
    }

    public static function tableListingVersions(): array
    {
        return [
            'legacy listing' => ['3.36.0', 'compileLegacyTables'],
            'current listing' => ['3.37.0', 'compileTables'],
        ];
    }

    public function testViewsAndSchemaStateReadsUseTheMetadataHook(): void
    {
        [$builder, $connection, $grammar, $processor] = $this->builder();
        $connection->allows('getTablePrefix')->returns('app_');
        $grammar->expects('compileViews')->with('main')->returns('views sql');
        $grammar->expects('compileColumns')->with('main', 'app_users')->returns('columns sql');
        $grammar->expects('compileIndexes')->with('main', 'app_users')->returns('indexes sql');
        $grammar->expects('compileForeignKeys')->with('main', 'app_users')->returns('foreign keys sql');
        $grammar->expects('compileSqlCreateStatement')->with('main', 'app_users')->returns('definition sql');
        $connection->expects('scalar')->never();
        $connection->expects('selectFromWriteConnection')->with('definition sql')->returns([(object) ['sql' => 'create table app_users (id integer)']]);

        foreach (['views', 'columns', 'indexes', 'foreign keys'] as $kind) {
            $connection->expects('selectFromWriteConnection')->with($kind . ' sql')->returns([(object) ['name' => $kind]]);
        }

        $processor->expects('processViews')->with([(object) ['name' => 'views']])->returns([['name' => 'active_users']]);
        $processor->expects('processColumns')->with([(object) ['name' => 'columns']], 'create table app_users (id integer)')->returns([['name' => 'id']]);
        $processor->expects('processIndexesForSchemaState')->with([(object) ['name' => 'indexes']])->returns([['name' => 'primary']]);
        $processor->expects('processForeignKeysForSchemaState')->with([(object) ['name' => 'foreign keys']])->returns([['implicit' => true]]);

        $this->assertSame([['name' => 'active_users']], $builder->getViews('main'));
        $this->assertSame(['columns' => [['name' => 'id']], 'sql' => 'create table app_users (id integer)'], $builder->getColumnsForSchemaState('main.users'));
        $this->assertSame([['name' => 'primary']], $builder->getIndexesForSchemaState('main.users'));
        $this->assertSame([['implicit' => true]], $builder->getForeignKeysForSchemaState('main.users'));
        $this->assertSame(['views sql', 'columns sql', 'definition sql', 'indexes sql', 'foreign keys sql'], $builder->metadataQueries);
    }

    #[DataProvider('storedDefinitions')]
    public function testStoredDefinitionRetainsScalarResultHandling(array $rows, string $sql): void
    {
        [$builder, $connection, $grammar, $processor] = $this->builder();
        $connection->allows('getTablePrefix')->returns('');
        $grammar->expects('compileColumns')->with('main', 'users')->returns('columns sql');
        $grammar->expects('compileSqlCreateStatement')->with('main', 'users')->returns('definition sql');
        $connection->expects('selectFromWriteConnection')->with('columns sql')->returns([]);
        $connection->expects('selectFromWriteConnection')->with('definition sql')->returns($rows);
        $connection->expects('scalar')->never();
        $processor->expects('processColumns')->with([], $sql)->returns([]);

        $this->assertSame(['columns' => [], 'sql' => $sql], $builder->getColumnsForSchemaState('main.users'));
        $this->assertSame(['columns sql', 'definition sql'], $builder->metadataQueries);
    }

    public static function storedDefinitions(): array
    {
        return [
            'no row' => [[], ''],
            'null definition' => [[(object) ['sql' => null]], ''],
            'array row' => [[['sql' => 'create table users (id integer)']], 'create table users (id integer)'],
        ];
    }

    public function testStoredDefinitionRejectsMultipleColumns(): void
    {
        [$builder, $connection, $grammar, $processor] = $this->builder();
        $connection->allows('getTablePrefix')->returns('');
        $grammar->expects('compileColumns')->with('main', 'users')->returns('columns sql');
        $grammar->expects('compileSqlCreateStatement')->with('main', 'users')->returns('definition sql');
        $connection->expects('selectFromWriteConnection')->with('columns sql')->returns([]);
        $connection->expects('selectFromWriteConnection')->with('definition sql')->returns([(object) ['sql' => 'create table users (id integer)', 'extra' => 1]]);
        $connection->expects('scalar')->never();
        $processor->expects('processColumns')->never();

        $this->expectException(MultipleColumnsSelectedException::class);

        $builder->getColumnsForSchemaState('main.users');
    }

    protected function builder(): array
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = Double::for(SQLiteGrammar::class)->strict();
        $processor = Double::for(SQLiteProcessor::class)->strict();
        $connection->allows('getSchemaGrammar')->returns($grammar);
        $connection->allows('getPostProcessor')->returns($processor);

        return [new SQLiteMetadataRecordingBuilder($connection), $connection, $grammar, $processor];
    }
}

class SQLiteMetadataRecordingBuilder extends SQLiteBuilder
{
    public array $metadataQueries = [];

    #[Override]
    protected function selectMetadata(string $query): array
    {
        $this->metadataQueries[] = $query;

        return parent::selectMetadata($query);
    }
}
