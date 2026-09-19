<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseMariaDbBuilderTest extends TestCase
{
    public function testCreateDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MariaDbGrammar($connection);

        $connection->expects('getConfig')->with('charset')->andReturn('utf8mb4');
        $connection->expects('getConfig')->with('collation')->andReturn('utf8mb4_unicode_ci');
        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'create database `my_temporary_database` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`'
        )->andReturn(true);

        $builder = new MariaDbBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new MariaDbGrammar($connection);

        $connection->expects('getSchemaGrammar')->andReturn($grammar);
        $connection->expects('statement')->with(
            'drop database if exists `my_database_a`'
        )->andReturn(true);

        $builder = new MariaDbBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }
}
