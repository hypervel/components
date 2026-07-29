<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Concerns;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Testbench\Foundation\Concerns\HandlesDatabaseConnections;
use Hypervel\Testbench\PHPUnit\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

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

    #[DataProvider('validPorts')]
    #[Test]
    public function itUsesDriverSpecificPort(string $port, int $expected): void
    {
        $config = new ConfigRepository;
        $config->set('database.connections.mysql.port', 3306);

        $_ENV['MYSQL_PORT'] = $port;

        try {
            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mysql', 'MYSQL');

            $this->assertSame($expected, $config->get('database.connections.mysql.port'));
        } finally {
            unset($_ENV['MYSQL_PORT']);
        }
    }

    public static function validPorts(): array
    {
        return [
            'lowest port' => ['1', 1],
            'leading zeroes' => ['03307', 3307],
            'highest port' => ['65535', 65535],
        ];
    }

    #[DataProvider('emptyPorts')]
    #[Test]
    public function itUsesConfiguredPortWhenDriverSpecificPortIsEmpty(string $port): void
    {
        $config = new ConfigRepository;
        $config->set('database.connections.mysql.port', 3306);

        $_ENV['MYSQL_PORT'] = $port;

        try {
            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mysql', 'MYSQL');

            $this->assertSame(3306, $config->get('database.connections.mysql.port'));
        } finally {
            unset($_ENV['MYSQL_PORT']);
        }
    }

    public static function emptyPorts(): array
    {
        return [
            'empty value' => [''],
            'empty sentinel' => ['(empty)'],
            'null sentinel' => ['(null)'],
        ];
    }

    #[DataProvider('invalidPorts')]
    #[Test]
    public function itRejectsInvalidDriverSpecificPort(string $port, string $rendered): void
    {
        $config = new ConfigRepository;

        $_ENV['MYSQL_PORT'] = $port;

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage(
                "Environment variable [MYSQL_PORT] must be a decimal port between 1 and 65535; {$rendered} given."
            );

            $this->usesDatabaseConnectionsEnvironmentVariables($config, 'mysql', 'MYSQL');
        } finally {
            unset($_ENV['MYSQL_PORT']);
        }
    }

    public static function invalidPorts(): array
    {
        return [
            'zero' => ['0', "'0'"],
            'above maximum' => ['65536', "'65536'"],
            'non-decimal character' => ['33O7', "'33O7'"],
            'boolean sentinel' => ['true', 'true'],
        ];
    }
}
