<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\MariaDbGrammar;
use Hypervel\Database\Schema\MariaDbBuilder;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DatabaseMariaDbBuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateDatabase(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MariaDbGrammar($connection);

        $connection->expects('getConfig')->with('charset')->returns('utf8mb4');
        $connection->expects('getConfig')->with('collation')->returns('utf8mb4_unicode_ci');
        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'create database `my_temporary_database` default character set `utf8mb4` default collate `utf8mb4_unicode_ci`'
        )->returns(true);

        $builder = new MariaDbBuilder($connection);
        $builder->createDatabase('my_temporary_database');
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new MariaDbGrammar($connection);

        $connection->expects('getSchemaGrammar')->returns($grammar);
        $connection->expects('statement')->with(
            'drop database if exists `my_database_a`'
        )->returns(true);

        $builder = new MariaDbBuilder($connection);

        $builder->dropDatabaseIfExists('my_database_a');
    }
}
