<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connectors\Connector;
use Hypervel\Database\Connectors\MySqlConnector;
use Hypervel\Database\Connectors\PostgresConnector;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseConnectorTest extends TestCase
{
    public function testOptionResolution()
    {
        $connector = new Connector;
        $connector->setDefaultOptions([0 => 'foo', 1 => 'bar']);
        $this->assertEquals([0 => 'baz', 1 => 'bar', 2 => 'boom'], $connector->getOptions(['options' => [0 => 'baz', 2 => 'boom']]));
    }

    #[DataProvider('mySqlConnectProvider')]
    public function testMySqlConnectCallsCreateConnectionWithProperArguments($dsn, $config)
    {
        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $connection->shouldReceive('exec')->once()->with('use `bar`;')->andReturn(true);
        $connection->shouldReceive('exec')->once()->with("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci';")->andReturn(true);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public static function mySqlConnectProvider()
    {
        return [
            ['mysql:host=foo;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8']],
            ['mysql:host=foo;port=111;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8']],
            ['mysql:unix_socket=baz;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'unix_socket' => 'baz', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8']],
        ];
    }

    public function testMySqlConnectCallsCreateConnectionWithIsolationLevel()
    {
        $dsn = 'mysql:host=foo;dbname=bar';
        $config = ['host' => 'foo', 'database' => 'bar', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8', 'isolation_level' => 'REPEATABLE READ'];

        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $connection->shouldReceive('exec')->once()->with('use `bar`;')->andReturn(true);
        $connection->shouldReceive('exec')->once()->with('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;')->andReturn(true);
        $connection->shouldReceive('exec')->once()->with("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci';")->andReturn(true);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresConnectCallsCreateConnectionWithProperArguments()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;client_encoding=\'utf8\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'charset' => 'utf8'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    /**
     * @param array|string $searchPath
     * @param string $expectedSearchPath Quoted search path (output of quoteSearchPath)
     */
    #[DataProvider('provideSearchPaths')]
    public function testPostgresSearchPathIsBakedIntoDsn($searchPath, $expectedSearchPath)
    {
        $config = ['host' => 'foo', 'database' => 'bar', 'search_path' => $searchPath, 'charset' => 'utf8'];
        $escaped = str_replace(' ', '\ ', $expectedSearchPath);
        $dsn = "pgsql:host=foo;dbname='bar';client_encoding='utf8';options='-c search_path={$escaped}'";

        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public static function provideSearchPaths()
    {
        return [
            'all-lowercase' => [
                'public',
                '"public"',
            ],
            'case-sensitive' => [
                'Public',
                '"Public"',
            ],
            'special characters' => [
                '¡foo_bar-Baz!.Áüõß',
                '"¡foo_bar-Baz!.Áüõß"',
            ],
            'single-quoted' => [
                "'public'",
                '"public"',
            ],
            'double-quoted' => [
                '"public"',
                '"public"',
            ],
            'variable' => [
                '$user',
                '"$user"',
            ],
            'delimit space' => [
                'public user',
                '"public", "user"',
            ],
            'delimit newline' => [
                "public\nuser\r\n\ttest",
                '"public", "user", "test"',
            ],
            'delimit comma' => [
                'public,user',
                '"public", "user"',
            ],
            'delimit comma and space' => [
                'public, user',
                '"public", "user"',
            ],
            'single-quoted many' => [
                "'public', 'user'",
                '"public", "user"',
            ],
            'double-quoted many' => [
                '"public", "user"',
                '"public", "user"',
            ],
            'quoted space is unsupported in string' => [
                '"public user"',
                '"public", "user"',
            ],
            'array' => [
                ['public', 'user'],
                '"public", "user"',
            ],
            'array with variable' => [
                ['public', '$user'],
                '"public", "$user"',
            ],
            'array with delimiter characters' => [
                ['public', '"user"', "'test'", 'spaced schema'],
                '"public", "user", "test", "spaced schema"',
            ],
        ];
    }

    public function testPostgresSearchPathFallbackToConfigKeySchemaIsBakedIntoDsn()
    {
        $config = ['host' => 'foo', 'database' => 'bar', 'schema' => ['public', '"user"'], 'charset' => 'utf8'];
        $dsn = 'pgsql:host=foo;dbname=\'bar\';client_encoding=\'utf8\';options=\'-c search_path="public",\ "user"\'';

        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresApplicationNameIsSet()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';client_encoding=\'utf8\';application_name=\'Laravel App\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'charset' => 'utf8', 'application_name' => 'Laravel App'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresApplicationUseAlternativeDatabaseName()
    {
        $dsn = 'pgsql:dbname=\'baz\'';
        $config = ['database' => 'bar', 'connect_via_database' => 'baz'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresApplicationUseAlternativeDatabaseNameAndPort()
    {
        $dsn = 'pgsql:dbname=\'baz\';port=2345';
        $config = ['database' => 'bar', 'connect_via_database' => 'baz', 'port' => 5432, 'connect_via_port' => 2345];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresIsolationLevelIsBakedIntoDsn()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;options=\'-c default_transaction_isolation=SERIALIZABLE\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'isolation_level' => 'SERIALIZABLE'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresIsolationLevelWithSpaceIsBackslashEscaped()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c default_transaction_isolation=read\ committed\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'isolation_level' => 'read committed'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresTimezoneIsBakedIntoDsn()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c TimeZone=UTC\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'timezone' => 'UTC'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresSynchronousCommitIsBakedIntoDsn()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c synchronous_commit=off\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'synchronous_commit' => 'off'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresCombinesMultipleStartupParamsInDsn()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c search_path="public" -c TimeZone=UTC -c default_transaction_isolation=SERIALIZABLE -c synchronous_commit=on\'';
        $config = [
            'host' => 'foo',
            'database' => 'bar',
            'search_path' => 'public',
            'timezone' => 'UTC',
            'isolation_level' => 'SERIALIZABLE',
            'synchronous_commit' => 'on',
        ];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresNoStartupParamsOmitsOptionsFromDsn()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\'';
        $config = ['host' => 'foo', 'database' => 'bar'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteMemoryDatabasesMayBeConnectedTo()
    {
        $dsn = 'sqlite::memory:';
        $config = ['database' => ':memory:'];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteNamedMemoryDatabasesMayBeConnectedTo()
    {
        $dsn = 'sqlite:file:mydb?mode=memory&cache=shared';
        $config = ['database' => 'file:mydb?mode=memory&cache=shared'];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteFileDatabasesMayBeConnectedTo()
    {
        $dsn = 'sqlite:' . __DIR__;
        $config = ['database' => __DIR__];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }
}
