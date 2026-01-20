<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Connections;

use Hyperf\Database\Connection;
use Hypervel\Database\Connections\MySqlConnection;
use Hypervel\Database\Connections\PostgreSqlConnection;
use Hypervel\Database\Connections\SQLiteConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Testbench\TestCase;
use PDO;

/**
 * Tests that custom connection resolvers are registered and return the
 * correct connection, builder, and grammar instances.
 *
 * @internal
 * @coversNothing
 */
class ConnectionRegistrationTest extends TestCase
{
    public function testMySqlConnectionResolverIsRegistered(): void
    {
        $resolver = Connection::getResolver('mysql');

        $this->assertNotNull($resolver, 'MySQL connection resolver should be registered');
    }

    public function testPostgreSqlConnectionResolverIsRegistered(): void
    {
        $resolver = Connection::getResolver('pgsql');

        $this->assertNotNull($resolver, 'PostgreSQL connection resolver should be registered');
    }

    public function testSqliteConnectionResolverIsRegistered(): void
    {
        $resolver = Connection::getResolver('sqlite');

        $this->assertNotNull($resolver, 'SQLite connection resolver should be registered');
    }

    public function testMySqlResolverReturnsCustomConnection(): void
    {
        $resolver = Connection::getResolver('mysql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', 'prefix_', []);

        $this->assertInstanceOf(MySqlConnection::class, $connection);
    }

    public function testPostgreSqlResolverReturnsCustomConnection(): void
    {
        $resolver = Connection::getResolver('pgsql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', 'prefix_', []);

        $this->assertInstanceOf(PostgreSqlConnection::class, $connection);
    }

    public function testSqliteResolverReturnsCustomConnection(): void
    {
        $resolver = Connection::getResolver('sqlite');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', 'prefix_', []);

        $this->assertInstanceOf(SQLiteConnection::class, $connection);
    }

    public function testMySqlConnectionReturnsCustomBuilder(): void
    {
        $resolver = Connection::getResolver('mysql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);
        $builder = $connection->query();

        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testPostgreSqlConnectionReturnsCustomBuilder(): void
    {
        $resolver = Connection::getResolver('pgsql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);
        $builder = $connection->query();

        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testSqliteConnectionReturnsCustomBuilder(): void
    {
        $resolver = Connection::getResolver('sqlite');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);
        $builder = $connection->query();

        $this->assertInstanceOf(Builder::class, $builder);
    }

    public function testMySqlConnectionUsesCustomGrammar(): void
    {
        $resolver = Connection::getResolver('mysql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);

        $this->assertInstanceOf(MySqlGrammar::class, $connection->getQueryGrammar());
    }

    public function testPostgreSqlConnectionUsesCustomGrammar(): void
    {
        $resolver = Connection::getResolver('pgsql');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);

        $this->assertInstanceOf(PostgresGrammar::class, $connection->getQueryGrammar());
    }

    public function testSqliteConnectionUsesCustomGrammar(): void
    {
        $resolver = Connection::getResolver('sqlite');
        $pdo = $this->createMock(PDO::class);

        $connection = $resolver($pdo, 'test_db', '', []);

        $this->assertInstanceOf(SQLiteGrammar::class, $connection->getQueryGrammar());
    }
}
