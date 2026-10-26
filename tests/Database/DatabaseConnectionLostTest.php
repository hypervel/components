<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use ErrorException;
use Exception;
use Hypervel\Database\LostConnectionDetector;
use Hypervel\Database\LostConnectionException;
use Hypervel\Database\QueryException;
use Hypervel\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class DatabaseConnectionLostTest extends TestCase
{
    #[DataProvider('shouldBeLostProvider')]
    public function testLostConnectionDetectorMatchesExceptionMessage(string $message): void
    {
        $detector = new LostConnectionDetector;
        $this->assertTrue($detector->causedByLostConnection(new PDOException($message)));
    }

    /**
     * Provide lost database connection messages.
     */
    public static function shouldBeLostProvider(): array
    {
        return [
            'SSL EOF' => ['SQLSTATE[HY000]: General error: 7 SSL SYSCALL error: EOF detected'],
            'PgBouncer crashed server' => ["SQLSTATE[HY000]: General error: 7 FATAL: server conn crashed?\nSSL SYSCALL error: EOF detected"],
            'PgBouncer idle timeout' => ["SQLSTATE[HY000]: General error: 7 FATAL: client_idle_timeout\nSSL SYSCALL error: EOF detected"],
            'DNS failure' => ['SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Try again'],
            'PostgreSQL connection refused' => ["SQLSTATE[08006] [7] connection to server at \"example.database.com\" (10.0.1.7), port 5432 failed: Connection refused\nIs the server running on that host and accepting TCP/IP connections? (Connection: pgsql, Host: example.database.com, Port: 5432, Database: forge, SQL: select * from \"cache\" where \"key\" in (hypervel:queue:restart))"],
            'MySQL server gone away' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
            'SSL/TLS alert' => ['SSL error: ssl/tls alert unexpected message'],
        ];
    }

    public function testLostConnectionDetectorDoesNotMatchUnrelatedEofMessage(): void
    {
        $detector = new LostConnectionDetector;

        $this->assertFalse($detector->causedByLostConnection(new Exception('Unexpected EOF while parsing application data')));
    }

    public function testQueryContextCannotForgeALostConnection(): void
    {
        $failure = new QueryException('test', 'insert into logs values (?)', ['Connection reset by peer'], new PDOException('Unique violation'));
        $nested = new QueryException('test', 'select 1', [], $failure);
        $detector = new LostConnectionDetector;

        $this->assertFalse($detector->causedByLostConnection($failure));
        $this->assertFalse($detector->causedByLostConnection($nested));
    }

    #[DataProvider('driverFailures')]
    public function testDriverFailuresAreDetectedThroughNestedQueryExceptions(Throwable $failure): void
    {
        $nested = new QueryException('test', 'select 1', [], new QueryException('test', 'select 2', [], $failure));
        $detector = new LostConnectionDetector;

        $this->assertTrue($detector->causedByLostConnection($failure));
        $this->assertTrue($detector->causedByLostConnection($nested));
    }

    /**
     * Provide native failures and the explicit lost-connection signal.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function driverFailures(): iterable
    {
        yield 'PDO' => [new PDOException('server has gone away')];
        yield 'explicit signal' => [new LostConnectionException('Lost connection and no reconnector available.')];
        yield 'mysqlnd packet warning' => [new ErrorException('Packets out of order. Expected 1 received 0. Packet size=145', 0, E_WARNING)];
        yield 'mysqlnd send warning' => [new ErrorException('Error while sending QUERY packet. PID=1234', 0, E_WARNING)];
    }

    #[DataProvider('nonDatabaseFailures')]
    public function testNonDatabaseFailuresAreNotDetected(Throwable $failure): void
    {
        $detector = new LostConnectionDetector;

        $this->assertFalse($detector->causedByLostConnection($failure));
        $this->assertFalse($detector->causedByLostConnection(new QueryException('test', 'select 1', [], $failure)));
    }

    /**
     * Provide failures whose messages resemble database failures.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function nonDatabaseFailures(): iterable
    {
        yield 'custom driver without a policy' => [new RuntimeException('server has gone away')];
        yield 'filesystem warning' => [new ErrorException('fopen(missing): Failed to open stream: No such file or directory', 0, E_WARNING)];
        yield 'embedded packet warning' => [new ErrorException('fopen(Packets out of order. Expected): Failed to open stream', 0, E_WARNING)];
        yield 'unsupported PDO warning mode' => [new ErrorException('PDO::query(): SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 0, E_WARNING)];
    }
}
