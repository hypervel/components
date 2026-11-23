<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\MySql;

use Hypervel\Database\Connectors\MySqlConnector;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase;
use PDO;

#[RequiresDatabase('mysql')]
class UnixSocketAuthenticationTest extends TestCase
{
    public function testCachingSha2PasswordAuthenticatesOverUnixSocketWithAnEmptyCache(): void
    {
        $configuration = DB::connection()->getConfig();

        if (empty($configuration['unix_socket'])) {
            $this->markTestSkipped('This test requires a configured MySQL Unix socket.');
        }

        $connector = new MySqlConnector;
        // Create a separate account over TCP without authenticating it or warming its cache.
        $administrator = $connector->connect(array_replace($configuration, ['unix_socket' => '']));

        if (version_compare($administrator->getAttribute(PDO::ATTR_SERVER_VERSION), '8.0', '<')) {
            $this->markTestSkipped('caching_sha2_password requires MySQL 8 or later.');
        }

        $username = 'hypervel_socket_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $administrator->exec("CREATE USER '{$username}'@'localhost' IDENTIFIED WITH caching_sha2_password BY '{$password}'");

        try {
            $connection = $connector->connect(array_replace($configuration, [
                'database' => '',
                'username' => $username,
                'password' => $password,
            ]));

            $this->assertSame(1, $connection->query('SELECT 1')->fetchColumn());
        } finally {
            unset($connection);
            $administrator->exec("DROP USER '{$username}'@'localhost'");
        }
    }
}
