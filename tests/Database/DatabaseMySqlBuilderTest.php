<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Grammars\MySqlGrammar as MySqlGrammarSchema;
use Hypervel\Database\Schema\MySqlBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class DatabaseMySqlBuilderTest extends TestCase
{
    public function testCreateDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getConfig')->with('charset')->andReturn('utf8mb4');
        $connection->expects('getConfig')->with('collation')->andReturn('utf8mb4_unicode_ci');
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'create database `my_temporary_database` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`'
        )->andReturn(true);

        $builder = new MySqlBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'drop database if exists `my_database_a`'
        )->andReturn(true);

        $builder = new MySqlBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }

    public function testDeleteWithJoinCompilesOrderByAndLimit(): void
    {
        $connection = m::mock(Connection::class);
        $processor = m::mock(Processor::class);
        $grammar = new MySqlGrammar($connection);

        $connection->expects('getTablePrefix')->times(5)->andReturn('');

        $builder = new Builder($connection, $grammar, $processor);

        $builder
            ->from('users')
            ->join('contacts', 'users.id', '=', 'contacts.id')
            ->where('email', '=', 'foo')
            ->orderBy('users.id')
            ->limit(5);

        $sql = $grammar->compileDelete($builder);

        $this->assertStringContainsString('order by `users`.`id` asc', $sql);
        $this->assertStringContainsString('limit 5', $sql);
    }

    public function testDropAllTablesPreservesEnabledForeignKeyConstraints(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('database');
        $builder->shouldReceive('getTableListing')->once()->with(['database'])->andReturn(['users']);
        $connection->shouldReceive('beginForeignKeyConstraintSuppression')->once()->andReturnTrue();
        $connection->shouldReceive('pretending')->times(3)->andReturnFalse();
        $connection->shouldReceive('scalar')->once()->with('select @@foreign_key_checks', [], false)->andReturn(1);
        $connection->shouldReceive('executeSessionStatement')->once()->with('SET FOREIGN_KEY_CHECKS=0;')->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables(['users']))
            ->andReturnTrue()
            ->ordered();
        $connection->shouldReceive('executeSessionStatement')->once()->with('SET FOREIGN_KEY_CHECKS=1;')->ordered();
        $connection->shouldReceive('endForeignKeyConstraintSuppression')->once();

        $builder->dropAllTables();
    }

    public function testDropAllTablesPreservesDisabledForeignKeyConstraints(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('database');
        $builder->shouldReceive('getTableListing')->once()->with(['database'])->andReturn(['users']);
        $connection->shouldReceive('beginForeignKeyConstraintSuppression')->once()->andReturnTrue();
        $connection->shouldReceive('pretending')->once()->andReturnFalse();
        $connection->shouldReceive('scalar')->once()->with('select @@foreign_key_checks', [], false)->andReturn(0);
        $connection->shouldNotReceive('executeSessionStatement');
        $connection->shouldReceive('statement')
            ->once()
            ->with($grammar->compileDropAllTables(['users']))
            ->andReturnTrue();
        $connection->shouldReceive('endForeignKeyConstraintSuppression')->once();

        $builder->dropAllTables();
    }

    public function testDropAllTablesPropagatesAFalseStatementResultAfterRestoringConstraints(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('database');
        $builder->shouldReceive('getTableListing')->once()->with(['database'])->andReturn(['users']);
        $connection->shouldReceive('beginForeignKeyConstraintSuppression')->once()->andReturnTrue();
        $connection->shouldReceive('pretending')->times(3)->andReturnFalse();
        $connection->shouldReceive('scalar')->once()->with('select @@foreign_key_checks', [], false)->andReturn(1);
        $connection->shouldReceive('executeSessionStatement')->once()->with('SET FOREIGN_KEY_CHECKS=0;')->ordered();
        $statement = $grammar->compileDropAllTables(['users']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse()->ordered();
        $connection->shouldReceive('executeSessionStatement')->once()->with('SET FOREIGN_KEY_CHECKS=1;')->ordered();
        $connection->shouldReceive('endForeignKeyConstraintSuppression')->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTables();
    }

    public function testDropAllViewsPropagatesAFalseStatementResult(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MySqlGrammarSchema($connection);

        $connection->shouldReceive('getSchemaGrammar')->once()->andReturn($grammar);
        $builder = m::mock(MySqlBuilder::class, [$connection])->makePartial();
        $builder->shouldReceive('getCurrentSchemaListing')->once()->andReturn(['database']);
        $builder->shouldReceive('getViews')->once()->with(['database'])->andReturn([
            ['schema_qualified_name' => 'active_users'],
        ]);
        $statement = $grammar->compileDropAllViews(['active_users']);
        $connection->shouldReceive('statement')->once()->with($statement)->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to execute schema statement [{$statement}].");

        $builder->dropAllViews();
    }
}
