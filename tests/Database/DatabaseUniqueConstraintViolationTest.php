<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Exception;
use Generator;
use Hypervel\Database\Connection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class DatabaseUniqueConstraintViolationTest extends TestCase
{
    #[DataProvider('unparseableConstraintProvider')]
    public function testUnparseableConstraintMessagesReturnEmptyMetadata(
        string $connectionClass,
        Exception $exception
    ): void {
        $connection = new $connectionClass(new DatabaseUniqueConstraintViolationPdoStub);
        $method = new ReflectionMethod($connection, 'parseUniqueConstraintViolation');

        $this->assertSame([
            'columns' => [],
            'index' => null,
        ], $method->invoke($connection, $exception));
    }

    /**
     * @return Generator<string, array{class-string<Connection>, Exception}>
     */
    public static function unparseableConstraintProvider(): Generator
    {
        yield 'mysql' => [
            MySqlConnection::class,
            new Exception('Integrity constraint violation: 1062 Duplicate entry'),
        ];

        yield 'postgres' => [
            PostgresConnection::class,
            new Exception('duplicate key value violates unique constraint'),
        ];

        yield 'sqlite' => [
            SQLiteConnection::class,
            new Exception('columns are not unique'),
        ];
    }
}

class DatabaseUniqueConstraintViolationPdoStub extends PDO
{
    public function __construct()
    {
    }
}
