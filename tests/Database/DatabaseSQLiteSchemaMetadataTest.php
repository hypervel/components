<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSQLiteSchemaMetadataTest extends TestCase
{
    #[DataProvider('tableListingVersions')]
    public function testBothTableListingPathsUseTheMetadataHook(string $version, string $compiler): void
    {
        [$builder, $connection, $grammar, $processor] = $this->builder();
        $connection->shouldReceive('getServerVersion')->once()->andReturn($version);
        $grammar->shouldReceive('compileDbstatExists')->once()->andReturn('dbstat sql');
        $connection->shouldReceive('scalar')->once()->with('dbstat sql')->andReturn(1);
        $grammar->shouldReceive($compiler)->once()->with('main', true)->andReturn('tables sql');
        $rows = [(object) ['name' => 'users']];
        $connection->shouldReceive('selectFromWriteConnection')->once()->with('tables sql')->andReturn($rows);
        $processor->shouldReceive('processTables')->once()->with($rows)->andReturn([['name' => 'users']]);

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
        $connection->shouldReceive('getTablePrefix')->andReturn('app_');
        $grammar->shouldReceive('compileViews')->once()->with('main')->andReturn('views sql');
        $grammar->shouldReceive('compileColumns')->once()->with('main', 'app_users')->andReturn('columns sql');
        $grammar->shouldReceive('compileIndexes')->once()->with('main', 'app_users')->andReturn('indexes sql');
        $grammar->shouldReceive('compileSqlCreateStatement')->once()->with('main', 'app_users')->andReturn('definition sql');
        $connection->shouldReceive('scalar')->once()->with('definition sql', [], false)->andReturn('create table app_users (id integer)');

        foreach (['views', 'columns', 'indexes'] as $kind) {
            $connection->shouldReceive('selectFromWriteConnection')->once()->with($kind . ' sql')->andReturn([(object) ['name' => $kind]]);
        }

        $processor->shouldReceive('processViews')->once()->with([(object) ['name' => 'views']])->andReturn([['name' => 'active_users']]);
        $processor->shouldReceive('processColumns')->once()->with([(object) ['name' => 'columns']], 'create table app_users (id integer)')->andReturn([['name' => 'id']]);
        $processor->shouldReceive('processIndexesForSchemaState')->once()->with([(object) ['name' => 'indexes']])->andReturn([['name' => 'primary']]);

        $this->assertSame([['name' => 'active_users']], $builder->getViews('main'));
        $this->assertSame(['columns' => [['name' => 'id']], 'sql' => 'create table app_users (id integer)'], $builder->getColumnsForSchemaState('main.users'));
        $this->assertSame([['name' => 'primary']], $builder->getIndexesForSchemaState('main.users'));
        $this->assertSame(['views sql', 'columns sql', 'indexes sql'], $builder->metadataQueries);
    }

    protected function builder(): array
    {
        $connection = m::mock(Connection::class);
        $grammar = m::mock(SQLiteGrammar::class);
        $processor = m::mock(SQLiteProcessor::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor);

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
