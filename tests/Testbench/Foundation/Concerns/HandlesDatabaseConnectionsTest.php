<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Concerns;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Testbench\Foundation\Concerns\HandlesDatabaseConnections;
use Hypervel\Testbench\PHPUnit\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class HandlesDatabaseConnectionsTest extends TestCase
{
    use HandlesDatabaseConnections;

    #[Test]
    public function itCanBuildMysqlConnection(): void
    {
        $config = m::mock(Repository::class);

        $_ENV['MYSQL_URL'] = 'mysql://127.0.0.1:3306';

        $config->shouldNotReceive('get')->with('database.connections.mysql.url')
            ->shouldReceive('get')->once()->with('database.connections.mysql.host')->andReturn('127.0.0.1')
            ->shouldReceive('get')->once()->with('database.connections.mysql.port')->andReturn('3306')
            ->shouldReceive('get')->once()->with('database.connections.mysql.database')->andReturn('hypervel')
            ->shouldReceive('get')->once()->with('database.connections.mysql.username')->andReturn('root')
            ->shouldReceive('get')->once()->with('database.connections.mysql.password')->andReturn('secret')
            ->shouldReceive('get')->once()->with('database.connections.mysql.collation')->andReturn('utf8mb4_0900_ai_ci')
            ->shouldReceive('set')->once()->with([
                'database.connections.mysql.url' => 'mysql://127.0.0.1:3306',
                'database.connections.mysql.host' => '127.0.0.1',
                'database.connections.mysql.port' => '3306',
                'database.connections.mysql.database' => 'hypervel',
                'database.connections.mysql.username' => 'root',
                'database.connections.mysql.password' => 'secret',
                'database.connections.mysql.collation' => 'utf8mb4_0900_ai_ci',
            ]);

        $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mysql', 'MYSQL');

        unset($_ENV['MYSQL_URL']);
    }
}
