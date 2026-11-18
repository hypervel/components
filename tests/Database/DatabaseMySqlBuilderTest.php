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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class DatabaseMySqlBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateDatabase(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getConfig')->with('charset')->returns('utf8mb4');
        $connection->expects('getConfig')->with('collation')->returns('utf8mb4_unicode_ci');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'create database `my_temporary_database` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`'
        )->returns(true);

        $builder = new MySqlBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'drop database if exists `my_database_a`'
        )->returns(true);

        $builder = new MySqlBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }

    public function testDeleteWithJoinCompilesOrderByAndLimit(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $processor = new Processor;
        $grammar = new MySqlGrammar($connection);

        $connection->expects('getTablePrefix')->times(5)->returns('');

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
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new MySqlBuilder($connection))->passthru();
        $connection->expects('getDatabaseName')->returns('database');
        $builder->expects('getTableListing')->with(['database'])->returns(['users']);
        $connection->expects('beginForeignKeyConstraintSuppression')->returns(true);
        $connection->expects('pretending')->times(3)->returns(false);
        $connection->expects('scalar')->with('select @@foreign_key_checks', [], false)->returns(1);
        $connection->expects('executeSessionStatement')->with('SET FOREIGN_KEY_CHECKS=0;')->ordered();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables(['users']))
            ->returns(true)
            ->ordered();
        $connection->expects('executeSessionStatement')->with('SET FOREIGN_KEY_CHECKS=1;')->ordered();
        $connection->expects('endForeignKeyConstraintSuppression');

        $builder->dropAllTables();
    }

    public function testDropAllTablesPreservesDisabledForeignKeyConstraints(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new MySqlBuilder($connection))->passthru();
        $connection->expects('getDatabaseName')->returns('database');
        $builder->expects('getTableListing')->with(['database'])->returns(['users']);
        $connection->expects('beginForeignKeyConstraintSuppression')->returns(true);
        $connection->expects('pretending')->returns(false);
        $connection->expects('scalar')->with('select @@foreign_key_checks', [], false)->returns(0);
        $connection->expects('executeSessionStatement')->never();
        $connection->expects('statement')
            ->with($grammar->compileDropAllTables(['users']))
            ->returns(true);
        $connection->expects('endForeignKeyConstraintSuppression');

        $builder->dropAllTables();
    }

    public function testDropAllTablesPropagatesAFalseStatementResultAfterRestoringConstraints(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new MySqlBuilder($connection))->passthru();
        $connection->expects('getDatabaseName')->returns('database');
        $builder->expects('getTableListing')->with(['database'])->returns(['users']);
        $connection->expects('beginForeignKeyConstraintSuppression')->returns(true);
        $connection->expects('pretending')->times(3)->returns(false);
        $connection->expects('scalar')->with('select @@foreign_key_checks', [], false)->returns(1);
        $connection->expects('executeSessionStatement')->with('SET FOREIGN_KEY_CHECKS=0;')->ordered();
        $statement = $grammar->compileDropAllTables(['users']);
        $connection->expects('statement')->with($statement)->returns(false)->ordered();
        $connection->expects('executeSessionStatement')->with('SET FOREIGN_KEY_CHECKS=1;')->ordered();
        $connection->expects('endForeignKeyConstraintSuppression');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllTables();
    }

    public function testDropAllViewsPropagatesAFalseStatementResult(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MySqlGrammarSchema($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $builder = Double::for(new MySqlBuilder($connection))->passthru();
        $builder->expects('getCurrentSchemaListing')->returns(['database']);
        $builder->expects('getViews')->with(['database'])->returns([
            ['schema_qualified_name' => 'active_users'],
        ]);
        $statement = $grammar->compileDropAllViews(['active_users']);
        $connection->expects('statement')->with($statement)->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs("Failed to execute schema statement [{$statement}].");

        $builder->dropAllViews();
    }
}
