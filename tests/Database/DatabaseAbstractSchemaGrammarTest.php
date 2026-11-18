<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Schema\Grammars\Grammar;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class DatabaseAbstractSchemaGrammarTest extends TestCase
{
    use VerifiesDoubles;

    public function testCreateDatabase(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('create database "foo"', $grammar->compileCreateDatabase('foo'));
    }

    public function testDropDatabaseIfExists(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $grammar = new class($connection) extends Grammar {
        };

        $this->assertSame('drop database if exists "foo"', $grammar->compileDropDatabaseIfExists('foo'));
    }

    #[TestWith(['compilePartitions', [null, 'users']])]
    #[TestWith(['compilePartitionAncestors', [['public.users_1']]])]
    #[TestWith(['compileCreateRangePartition', ['users', 'users_1', [1], [2]]])]
    public function testPartitioningIsUnsupportedByDefault(string $method, array $arguments): void
    {
        $connection = Double::for(Connection::class)->strict();
        $grammar = new class($connection) extends Grammar {
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('This database driver does not support table partitioning.');

        $grammar->{$method}(...$arguments);
    }
}
