<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\LostConnectionDetector;
use Hypervel\Tests\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseConnectionLostTest extends TestCase
{
    #[DataProvider('shouldBeLostProvider')]
    public function testLostConnectionDetectorMatchesExceptionMessage(string $message): void
    {
        $detector = new LostConnectionDetector;
        $this->assertTrue($detector->causedByLostConnection(new PDOException($message)));
    }

    public static function shouldBeLostProvider(): array
    {
        return [
            'DNS failure' => ['SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed: Try again'],
            'PostgreSQL connection refused' => ["SQLSTATE[08006] [7] connection to server at \"example.database.com\" (10.0.1.7), port 5432 failed: Connection refused\nIs the server running on that host and accepting TCP/IP connections? (Connection: pgsql, Host: example.database.com, Port: 5432, Database: forge, SQL: select * from \"cache\" where \"key\" in (hypervel:queue:restart))"],
            'MySQL server gone away' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
            'SSL/TLS alert' => ['SSL error: ssl/tls alert unexpected message'],
        ];
    }
}
