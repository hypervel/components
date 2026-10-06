<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class DatabaseAbstractSchemaGrammarTest extends TestCase
{
    public function testCreateDatabase(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('create database "foo"', $grammar->compileCreateDatabase('foo'));
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('drop database if exists "foo"', $grammar->compileDropDatabaseIfExists('foo'));
    }

    #[TestWith(['compilePartitions', [null, 'users']])]
    #[TestWith(['compilePartitionAncestors', [['public.users_1']]])]
    #[TestWith(['compileCreateRangePartition', ['users', 'users_1', [1], [2]]])]
    public function testPartitioningIsUnsupportedByDefault(string $method, array $arguments): void
    {
        $connection = m::mock(Connection::class);
        $grammar = new class($connection) extends Grammar {
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This database driver does not support table partitioning.');

        $grammar->{$method}(...$arguments);
    }
}
