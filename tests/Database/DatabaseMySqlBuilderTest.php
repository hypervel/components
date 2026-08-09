<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\MySqlGrammar;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

class DatabaseMySqlBuilderTest extends TestCase
{
    public function testCreateDatabase()
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammar($connection);

        $connection->shouldReceive('getConfig')->once()->with('charset')->andReturn('utf8mb4');
        $connection->shouldReceive('getConfig')->once()->with('collation')->andReturn('utf8mb4_unicode_ci');
        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('statement')->once()->with(
            'create database `my_temporary_database` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`'
        )->andReturn(true);

        $builder = new MySqlBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists()
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $connection->shouldReceive('statement')->once()->with(
            'drop database if exists `my_database_a`'
        )->andReturn(true);

        $builder = new MySqlBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }

    public function testDropAllTablesPreservesEnabledForeignKeyConstraints(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammar($connection);
        $pdo = m::mock(PDO::class);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('database');
        $builder->shouldReceive('getTableListing')->once()->with(['database'])->andReturn(['users']);
        $connection->shouldReceive('beginForeignKeyConstraintSuppression')->once()->andReturnTrue();
        $connection->shouldReceive('pretending')->times(3)->andReturnFalse();
        $connection->shouldReceive('scalar')->once()->with('select @@foreign_key_checks')->andReturn(1);
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $pdo->shouldReceive('exec')->once()->with('SET FOREIGN_KEY_CHECKS=0;')->andReturn(0)->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables(['users']))
            ->andReturnTrue()
            ->ordered();
        $pdo->shouldReceive('exec')->once()->with('SET FOREIGN_KEY_CHECKS=1;')->andReturn(0)->ordered();
        $connection->shouldReceive('endForeignKeyConstraintSuppression')->once();

        $builder->dropAllTables();
    }

    public function testDropAllTablesPreservesDisabledForeignKeyConstraints(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammar($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('database');
        $builder->shouldReceive('getTableListing')->once()->with(['database'])->andReturn(['users']);
        $connection->shouldReceive('beginForeignKeyConstraintSuppression')->once()->andReturnTrue();
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('scalar')->once()->with('select @@foreign_key_checks')->andReturn(0);
        $connection->shouldReceive('getPdo')->never();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables(['users']))
            ->andReturnTrue();
        $connection->shouldReceive('endForeignKeyConstraintSuppression')->once();

        $builder->dropAllTables();
    }
}
