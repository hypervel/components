<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connectors\Connector;
use Hypervel\Database\Connectors\MariaDbConnector;
use Hypervel\Database\Connectors\MySqlConnector;
use Hypervel\Database\Connectors\PostgresConnector;
use Hypervel\Database\Connectors\SQLiteConnector;
use Hypervel\Database\SQLiteDatabaseDoesNotExistException;
use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseConnectorTest extends TestCase
{
    public function testOptionResolution()
    {
        $connector = new Connector;
        $connector->setDefaultOptions([0 => 'foo', 1 => 'bar']);
        $this->assertEquals([0 => 'baz', 1 => 'bar', 2 => 'boom'], $connector->getOptions(['options' => [0 => 'baz', 2 => 'boom']]));
    }

    #[DataProvider('mySqlConnectProvider')]
    public function testMySqlConnectCallsCreateConnectionWithProperArguments(string $dsn, array $config): void
    {
        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $connection->expects('exec')->with('use `bar`;')->andReturn(true);
        $connection->expects('exec')->with("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci', SESSION sql_mode='';")->andReturn(true);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public static function mySqlConnectProvider()
    {
        return [
            ['mysql:host=foo;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8', 'modes' => []]],
            ['mysql:host=foo;port=111;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8', 'modes' => []]],
            ['mysql:unix_socket=baz;dbname=bar', ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'unix_socket' => 'baz', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8', 'modes' => []]],
        ];
    }

    public function testConnectTimeoutUsesCeilingUnlessThePdoOptionIsExplicit(): void
    {
        foreach ([new MySqlConnector, new MariaDbConnector, new PostgresConnector] as $connector) {
            $this->assertSame(2, $connector->getOptions([
                'connect_timeout' => 1.25,
            ])[PDO::ATTR_TIMEOUT]);

            $this->assertSame(7, $connector->getOptions([
                'connect_timeout' => 1.25,
                'options' => [PDO::ATTR_TIMEOUT => 7],
            ])[PDO::ATTR_TIMEOUT]);
        }
    }

    #[TestWith(['pgsql'])]
    #[TestWith(['mysql'])]
    public function testConnectionCancellationEscapesWithoutRetrying(string $driver): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Swoole 6.2.3 and earlier mask PDO connection cancellation with PDOException.');
        }

        $result = $this->runUnresponsiveConnection($driver, 'cancel', true);

        $this->assertSame(CanceledException::class, $result['exception']);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testPostgresConnectionTimeoutStopsAnUnresponsiveHandshake(bool $hooked): void
    {
        if (SWOOLE_VERSION_ID <= 60203) {
            $this->markTestSkipped('Swoole 6.2.3 and earlier do not enforce the PostgreSQL connection deadline.');
        }

        $result = $this->runUnresponsiveConnection('pgsql', 'timeout', $hooked);

        $this->assertSame(PDOException::class, $result['exception']);
        $this->assertStringContainsString('timeout', strtolower($result['message']));
        $this->assertGreaterThanOrEqual(0.8, $result['elapsed']);
        $this->assertLessThan(4.0, $result['elapsed']);
    }

    /**
     * Exercise native connection setup in a process with a hard deadline.
     *
     * @return array{exception: ?class-string<Throwable>, message: ?string, elapsed: float}
     */
    protected function runUnresponsiveConnection(string $driver, string $operation, bool $hooked): array
    {
        if (! extension_loaded('pdo_' . $driver)) {
            $this->markTestSkipped('The PDO ' . $driver . ' extension is required.');
        }

        $process = new Process([
            PHP_BINARY,
            __DIR__ . '/Fixtures/ConnectToUnresponsiveServer.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $driver,
            $operation,
            $hooked ? 'yes' : 'no',
        ]);
        $process->setTimeout(5.0);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testMySqlEscapesBackticksInTheSelectedDatabaseName(): void
    {
        $config = ['host' => 'foo', 'database' => 'app`tenant', 'modes' => []];
        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('createConnection')->willReturn($connection);
        $connection->expects('exec')->with('use `app``tenant`;')->andReturn(true);
        $connection->expects('exec')->with("SET SESSION sql_mode='';")->andReturn(true);

        $this->assertSame($connection, $connector->connect($config));
    }

    public function testMySqlConnectCallsCreateConnectionWithIsolationLevel(): void
    {
        $dsn = 'mysql:host=foo;dbname=bar';
        $config = ['host' => 'foo', 'database' => 'bar', 'collation' => 'utf8_unicode_ci', 'charset' => 'utf8', 'isolation_level' => 'REPEATABLE READ', 'modes' => []];

        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $connection->expects('exec')->with('use `bar`;')->andReturn(true);
        $connection->expects('exec')->with('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;')->andReturn(true);
        $connection->expects('exec')->with("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci', SESSION sql_mode='';")->andReturn(true);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testMySqlLockTimeoutIsCombinedWithConnectionSettings(): void
    {
        $config = [
            'host' => 'foo',
            'database' => 'bar',
            'charset' => 'utf8',
            'lock_timeout' => 2,
            'strict' => false,
        ];

        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->willReturn($connection);
        $connection->expects('exec')->with('use `bar`;')->andReturn(true);
        $connection->expects('exec')->with("SET NAMES 'utf8', SESSION innodb_lock_wait_timeout=2, SESSION lock_wait_timeout=2, SESSION sql_mode='NO_ENGINE_SUBSTITUTION';")->andReturn(true);

        $this->assertSame($connection, $connector->connect($config));
    }

    public function testMySqlRejectsInvalidLockTimeout(): void
    {
        $config = ['host' => 'foo', 'database' => '', 'lock_timeout' => 0];
        $connector = $this->getMockBuilder(MySqlConnector::class)->onlyMethods(['createConnection'])->getMock();
        $connector->expects($this->never())->method('createConnection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection [lock_timeout] must be a positive integer.');

        $connector->connect($config);
    }

    public function testMySqlAndMariaDbRequireAnExplicitSqlModeConfiguration(): void
    {
        foreach ([MySqlConnector::class => 'MySQL', MariaDbConnector::class => 'MariaDB'] as $connectorClass => $driver) {
            $connector = $this->getMockBuilder($connectorClass)->onlyMethods(['createConnection'])->getMock();
            $connector->expects($this->once())->method('createConnection')->willReturn(m::mock(PDO::class));

            try {
                $connector->connect(['host' => 'localhost', 'database' => '']);
                $this->fail('Expected missing SQL mode configuration to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame("{$driver} connections must configure [strict] or [modes] so SQL literals can be escaped correctly.", $exception->getMessage());
            }
        }
    }

    public function testPostgresConnectCallsCreateConnectionWithProperArguments(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;client_encoding=\'utf8\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'charset' => 'utf8'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresLockTimeoutIsBakedIntoDsn(): void
    {
        $dsn = "pgsql:host=foo;dbname='bar';options='-c lock_timeout=2s'";
        $config = ['host' => 'foo', 'database' => 'bar', 'lock_timeout' => 2];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);

        $this->assertSame($connection, $connector->connect($config));
    }

    public function testPostgresRejectsInvalidLockTimeout(): void
    {
        $config = ['host' => 'foo', 'database' => 'bar', 'lock_timeout' => '2'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection'])->getMock();
        $connector->expects($this->never())->method('createConnection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection [lock_timeout] must be a positive integer.');

        $connector->connect($config);
    }

    /**
     * @param string $expectedSearchPath Quoted search path (output of quoteSearchPath)
     */
    #[DataProvider('provideSearchPaths')]
    public function testPostgresSearchPathIsSet(array|string $searchPath, string $expectedSearchPath): void
    {
        $config = ['host' => 'foo', 'database' => 'bar', 'search_path' => $searchPath, 'charset' => 'utf8'];
        // libpq first unquotes the connection value; the server then splits
        // the options arguments, requiring a second escaping layer for spaces.
        $escaped = str_replace(' ', '\\\ ', $expectedSearchPath);
        $dsn = "pgsql:host=foo;dbname='bar';client_encoding='utf8';options='-c search_path={$escaped}'";

        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    /**
     * Provide search paths and their quoted identifiers.
     */
    public static function provideSearchPaths(): array
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
            'array with embedded quote' => [
                ['team"reports'],
                '"team""reports"',
            ],
            'array with delimiter characters' => [
                ['public', '"user"', "'test'", 'spaced schema'],
                '"public", "user", "test", "spaced schema"',
            ],
        ];
    }

    public function testPostgresSearchPathFallbackToConfigKeySchema(): void
    {
        $config = ['host' => 'foo', 'database' => 'bar', 'schema' => ['public', '"user"'], 'charset' => 'utf8'];
        $dsn = 'pgsql:host=foo;dbname=\'bar\';client_encoding=\'utf8\';options=\'-c search_path="public",\\\ "user"\'';

        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresApplicationNameIsSet(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';client_encoding=\'utf8\';application_name=\'Hypervel App\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'charset' => 'utf8', 'application_name' => 'Hypervel App'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresKeepaliveOptionsAreSet(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;keepalives=1;keepalives_idle=600;keepalives_interval=30;keepalives_count=5';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'keepalives' => 1, 'keepalives_idle' => 600, 'keepalives_interval' => 30, 'keepalives_count' => 5];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresKeepaliveOptionsAreOmittedWhenNotConfigured(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;keepalives_idle=600';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'keepalives_idle' => 600];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresServerOptionsAreSet(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;options=\'-c statement_timeout=5s -c search_path=public\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'server_options' => ['statement_timeout' => '5s', 'search_path' => 'public']];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresServerOptionsAreOmittedWhenNotConfigured(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'server_options' => []];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresServerOptionValuesAreEscaped(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;options=\'-c application_name=my\\\ app -c custom.tag=o\\\'clock\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'server_options' => ['application_name' => 'my app', 'custom.tag' => "o'clock"]];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresConnectionValuesAreQuoted(): void
    {
        $config = [
            'database' => "team's\\database",
            'application_name' => "team's\\app",
            'charset' => 'utf8',
            'sslcert' => '/tmp/client cert.pem',
            'sslkey' => '/tmp/client key.pem',
            'sslrootcert' => '/tmp/root ca.pem',
        ];
        $dsn = <<<'DSN'
            pgsql:dbname='team\'s\\database';client_encoding='utf8';application_name='team\'s\\app';sslcert='/tmp/client cert.pem';sslkey='/tmp/client key.pem';sslrootcert='/tmp/root ca.pem'
            DSN;
        $connection = m::mock(PDO::class);
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection'])->getMock();
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, $connector->getOptions($config))->willReturn($connection);

        $this->assertSame($connection, $connector->connect($config));
    }

    public function testPostgresApplicationUseAlternativeDatabaseName(): void
    {
        $dsn = 'pgsql:dbname=\'baz\'';
        $config = ['database' => 'bar', 'connect_via_database' => 'baz'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresApplicationUseAlternativeDatabaseNameAndPort(): void
    {
        $dsn = 'pgsql:dbname=\'baz\';port=2345';
        $config = ['database' => 'bar', 'connect_via_database' => 'baz', 'port' => 5432, 'connect_via_port' => 2345];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresConnectorReadsIsolationLevelFromConfig(): void
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';port=111;options=\'-c default_transaction_isolation=SERIALIZABLE\'';
        $config = ['host' => 'foo', 'database' => 'bar', 'port' => 111, 'isolation_level' => 'SERIALIZABLE'];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testPostgresIsolationLevelWithSpaceIsBackslashEscaped()
    {
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c default_transaction_isolation=read\\\ committed\'';
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
        $dsn = 'pgsql:host=foo;dbname=\'bar\';options=\'-c statement_timeout=5s -c TimeZone=Asia/Tokyo -c search_path="public" -c TimeZone=UTC -c default_transaction_isolation=SERIALIZABLE -c lock_timeout=2s -c synchronous_commit=on\'';
        $config = [
            'host' => 'foo',
            'server_options' => ['statement_timeout' => '5s', 'TimeZone' => 'Asia/Tokyo'],
            'database' => 'bar',
            'search_path' => 'public',
            'timezone' => 'UTC',
            'isolation_level' => 'SERIALIZABLE',
            'lock_timeout' => 2,
            'synchronous_commit' => 'on',
        ];
        $connector = $this->getMockBuilder(PostgresConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteMemoryDatabasesMayBeConnectedTo(): void
    {
        $dsn = 'sqlite::memory:';
        $config = ['database' => ':memory:'];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteRejectsLockTimeout(): void
    {
        $config = ['database' => ':memory:', 'lock_timeout' => 2];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection'])->getMock();
        $connector->expects($this->never())->method('createConnection');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite connections use [busy_timeout] instead of [lock_timeout].');

        $connector->connect($config);
    }

    /**
     * Reject missing SQLite paths when no application root exists.
     */
    #[DataProvider('missingSQLitePathProvider')]
    public function testSQLiteRejectsMissingPathsWithoutAnApplicationRoot(bool $absolute): void
    {
        $database = 'missing-' . bin2hex(random_bytes(16)) . '.sqlite';

        if ($absolute) {
            $database = sys_get_temp_dir() . '/' . $database;
        }

        $process = new Process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
                require $argv[1];

                $container = new \Hypervel\Container\Container;
                \Hypervel\Container\Container::setInstance($container);

                $connector = new class extends \Hypervel\Database\Connectors\SQLiteConnector {
                    public function createConnection(string $dsn, array $config, array $options): \PDO
                    {
                        throw new \LogicException('The missing path unexpectedly reached connection creation.');
                    }
                };

                try {
                    $connector->connect(['database' => $argv[2]]);
                    $exception = null;
                } catch (\Throwable $throwable) {
                    $exception = $throwable;
                }

                echo json_encode([
                    'base_path_defined' => defined('BASE_PATH'),
                    'application_bound' => $container->has(\Hypervel\Contracts\Foundation\Application::class),
                    'exception' => $exception === null ? null : $exception::class,
                    'path' => $exception instanceof \Hypervel\Database\SQLiteDatabaseDoesNotExistException
                        ? $exception->path
                        : null,
                ], JSON_THROW_ON_ERROR);
                PHP,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $database,
        ]);
        $process->mustRun();

        $this->assertSame([
            'base_path_defined' => false,
            'application_bound' => false,
            'exception' => SQLiteDatabaseDoesNotExistException::class,
            'path' => $database,
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Provide missing absolute and relative SQLite paths.
     */
    public static function missingSQLitePathProvider(): array
    {
        return [
            'absolute' => [true],
            'relative' => [false],
        ];
    }

    /**
     * Resolve relative SQLite paths against the application root.
     */
    public function testSQLiteRelativePathResolvesAgainstApplicationRoot(): void
    {
        new Application(__DIR__);

        $config = ['database' => basename(__FILE__)];
        $dsn = 'sqlite:' . __FILE__;
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);

        $this->assertTrue(Application::getInstance()->has(ApplicationContract::class));
        $this->assertSame($connection, $connector->connect($config));
    }

    public function testSQLiteNamedMemoryDatabasesMayBeConnectedTo(): void
    {
        $dsn = 'sqlite:file:mydb?mode=memory&cache=shared';
        $config = ['database' => 'file:mydb?mode=memory&cache=shared'];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteFileUriDatabasesMayBeConnectedTo(): void
    {
        $dsn = 'sqlite:file:/tmp/database.sqlite?mode=rw';
        $config = ['database' => 'file:/tmp/database.sqlite?mode=rw'];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);

        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public function testSQLiteFileDatabasesMayBeConnectedTo(): void
    {
        $dsn = 'sqlite:' . __DIR__;
        $config = ['database' => __DIR__];
        $connector = $this->getMockBuilder(SQLiteConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(PDO::class);
        $connector->expects($this->once())->method('getOptions')->with($config)->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($dsn, $config, ['options'])->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }
}
