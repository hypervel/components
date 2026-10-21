<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PDOException;

class DatabaseQueryExceptionTest extends TestCase
{
    public function testIfItEmbedsBindingsIntoSql(): void
    {
        $connection = $this->getMockConnection();

        $sql = 'SELECT * FROM huehue WHERE a = ? and hue = ?';
        $bindings = [1, 'br'];

        $expectedSql = "SELECT * FROM huehue WHERE a = 1 and hue = 'br'";

        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException($connection->getName(), $sql, $bindings, $pdoException);

        DB::shouldReceive('connection')->andReturn($connection);
        $result = $exception->getRawSql();

        $this->assertSame($expectedSql, $result);
    }

    public function testIfItReturnsSameSqlWhenThereAreNoBindings(): void
    {
        $connection = $this->getMockConnection();

        $sql = "SELECT * FROM huehue WHERE a = 1 and hue = 'br'";
        $bindings = [];

        $expectedSql = $sql;

        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException($connection->getName(), $sql, $bindings, $pdoException);

        DB::shouldReceive('connection')->andReturn($connection);
        $result = $exception->getRawSql();

        $this->assertSame($expectedSql, $result);
    }

    public function testMessageIncludesConnectionInfo(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000] [2002] No such file or directory');
        $exception = new QueryException('mysql::read', 'SELECT * FROM users', [], $pdoException, [
            'driver' => 'mysql',
            'name' => 'mysql::read',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'hypervel_db',
            'unix_socket' => null,
        ]);

        $this->assertStringContainsString('Host: 192.168.1.10', $exception->getMessage());
        $this->assertStringContainsString('Port: 3306', $exception->getMessage());
        $this->assertStringContainsString('Database: hypervel_db', $exception->getMessage());
        $this->assertStringContainsString('Connection: mysql::read', $exception->getMessage());
    }

    public function testMessageIncludesUnixSocket(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000] [2002] No such file or directory');
        $exception = new QueryException('mysql', 'SELECT * FROM users', [], $pdoException, [
            'driver' => 'mysql',
            'unix_socket' => '/tmp/mysql.sock',
            'database' => 'hypervel_db',
        ]);

        $this->assertStringContainsString('Socket: /tmp/mysql.sock', $exception->getMessage());
        $this->assertStringContainsString('Database: hypervel_db', $exception->getMessage());
        $this->assertStringNotContainsString('Host:', $exception->getMessage());
    }

    public function testMessageHandlesArrayHosts(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000] [2002] No such file or directory');
        $exception = new QueryException('mysql::read', 'SELECT * FROM users', [], $pdoException, [
            'driver' => 'mysql',
            'host' => ['192.168.1.10', '192.168.1.11'],
            'port' => '3306',
            'database' => 'hypervel_db',
        ]);

        $this->assertStringContainsString('Host: 192.168.1.10, 192.168.1.11', $exception->getMessage());
    }

    public function testMessageHandlesEmptyConnectionInfo(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000] [2002] No such file or directory');
        $exception = new QueryException('mysql', 'SELECT * FROM users', [], $pdoException, [
            'driver' => 'mysql',
            'host' => '',
            'port' => '',
            'database' => '',
        ]);

        $this->assertStringContainsString('Host: ,', $exception->getMessage());
        $this->assertStringContainsString('Database: ', $exception->getMessage());
    }

    public function testMessageForSqliteOnlyShowsDatabase(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000]: General error: 1 no such table');
        $exception = new QueryException('sqlite', 'SELECT * FROM users', [], $pdoException, [
            'driver' => 'sqlite',
            'name' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => '/path/to/database.sqlite',
            'unix_socket' => null,
        ]);

        $this->assertStringContainsString('Database: /path/to/database.sqlite', $exception->getMessage());
        $this->assertStringNotContainsString('Host:', $exception->getMessage());
        $this->assertStringNotContainsString('Port:', $exception->getMessage());
    }

    public function testGetConnectionInfoReturnsConnectionInfo(): void
    {
        $pdoException = new PDOException('Mock error');
        $connectionInfo = [
            'driver' => 'mysql',
            'name' => 'mysql::read',
            'host' => '192.168.1.10',
            'port' => '3306',
            'database' => 'hypervel_db',
            'unix_socket' => null,
        ];
        $exception = new QueryException('mysql::read', 'SELECT * FROM users', [], $pdoException, $connectionInfo);

        $this->assertSame($connectionInfo, $exception->getConnectionDetails());
    }

    public function testBackwardCompatibilityWithoutConnectionInfo(): void
    {
        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException('mysql', 'SELECT * FROM users WHERE id = ?', [1], $pdoException);

        $this->assertSame('Mock SQL error (Connection: mysql, SQL: SELECT * FROM users WHERE id = 1)', $exception->getMessage());
        $this->assertSame([], $exception->getConnectionDetails());
    }

    public function testBindingsAreEmbeddedInTheMessageByDefault(): void
    {
        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException('mysql', 'SELECT * FROM users WHERE email = ?', ['foo@example.com'], $pdoException);

        $this->assertSame('Mock SQL error (Connection: mysql, SQL: SELECT * FROM users WHERE email = foo@example.com)', $exception->getMessage());
    }

    public function testBindingsCanBeMaskedInTheMessage(): void
    {
        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException('mysql', 'SELECT * FROM users WHERE email = ?', ['foo@example.com'], $pdoException, [], null, true);

        $this->assertSame('Mock SQL error (Connection: mysql, SQL: SELECT * FROM users WHERE email = ?)', $exception->getMessage());
    }

    public function testMaskingBindingsDoesNotAffectTheAccessors(): void
    {
        $pdoException = new PDOException('Mock SQL error');
        $exception = new QueryException('mysql', 'SELECT * FROM users WHERE email = ?', ['foo@example.com'], $pdoException, [], null, true);

        $this->assertSame(['foo@example.com'], $exception->getBindings());
        $this->assertSame('SELECT * FROM users WHERE email = ?', $exception->getSql());
    }

    /**
     * Create a connection for SQL rendering assertions.
     */
    protected function getMockConnection(): Connection
    {
        $connection = m::mock(Connection::class);

        $grammar = new Grammar($connection);

        $connection->shouldReceive('getName')->andReturn('default');
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('escape')->with(1, false)->andReturn(1);
        $connection->shouldReceive('escape')->with('br', false)->andReturn("'br'");

        return $connection;
    }
}
