<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectorInterface;
use Hypervel\Doctor\Diagnostics\DatabaseConnectionIsReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Doctor\TestCase;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class DatabaseConnectionIsReachableTest extends TestCase
{
    #[DataProvider('databaseConfigurations')]
    public function testChecksTheDefaultDatabaseConnection(array $configuration): void
    {
        config([
            'database.default' => 'testing',
            'database.connections.testing' => $configuration,
            'database.connections.unused' => [
                'driver' => 'unsupported',
            ],
        ]);

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame('pass', $result->status->value, $result->details ?? '');
        $this->assertSame('database-connection-is-reachable.reachable', $result->code);
        $this->assertSame('The application can connect to the default database connection.', $result->summary);
    }

    /**
     * Provide named and URL-configured SQLite connections.
     */
    public static function databaseConfigurations(): array
    {
        return [
            'aliased connection' => [['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
            'URL configuration' => [['url' => 'sqlite:///:memory:']],
        ];
    }

    #[DataProvider('pdoTimeouts')]
    public function testAddsABoundedPdoTimeoutToTransientProbes(?int $timeout): void
    {
        $probe = new stdClass;
        $probe->configuration = null;

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'hypervel',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'options' => $timeout === null ? [] : [PDO::ATTR_TIMEOUT => $timeout],
            ],
        ]);

        $this->app->bind('db.connector.pgsql', fn (): ConnectorInterface => new class($probe) implements ConnectorInterface {
            /**
             * Capture the connector configuration.
             */
            public function __construct(private stdClass $probe)
            {
            }

            /**
             * Open a local PDO connection for the probe.
             *
             * @param array<string, mixed> $config
             */
            public function connect(array $config): PDO
            {
                $this->probe->configuration = $config;

                return new PDO('sqlite::memory:');
            }
        });

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame('pass', $result->status->value, $result->details ?? '');
        $this->assertIsArray($probe->configuration);
        $this->assertArrayNotHasKey('connect_timeout', $probe->configuration);
        $this->assertSame($timeout ?? 2, $probe->configuration['options'][PDO::ATTR_TIMEOUT]);
    }

    /**
     * Provide default and explicit PDO timeouts.
     */
    public static function pdoTimeouts(): array
    {
        return [[null], [7]];
    }

    public function testLinksToDatabaseConfigurationWhenTheConnectionCannotBeReached(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'hypervel',
            ],
        ]);

        $this->app->bind('db.connector.pgsql', fn (): ConnectorInterface => new class implements ConnectorInterface {
            /**
             * Simulate an unavailable database.
             *
             * @param array<string, mixed> $config
             */
            public function connect(array $config): PDO
            {
                throw new RuntimeException('Database unavailable.');
            }
        });

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertSame('Database unavailable.', $result->details);
        $this->assertEquals([Link::docs('database', 'configuration')], $result->links);
    }

    #[DataProvider('customConnectionOutcomes')]
    public function testCustomDriverNeutralConnectionsAreProbedAndDisconnected(bool $fails): void
    {
        config([
            'database.default' => 'analytics',
            'database.connections.analytics' => ['driver' => 'custom', 'options' => 'transport-owned'],
        ]);
        $connection = m::mock(Connection::class);
        $version = $connection->expects('getServerVersion');

        if ($fails) {
            $version->andThrow(new RuntimeException('Custom transport unavailable.'));
        } else {
            $version->andReturn('1.0');
        }

        $connection->expects('disconnect');
        DB::extend('custom', function (array $configuration, string $name) use ($connection): Connection {
            $this->assertSame('analytics', $name);
            $this->assertSame('custom', $configuration['driver']);
            $this->assertSame('transport-owned', $configuration['options']);

            return $connection;
        });

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame($fails ? 'fail' : 'pass', $result->status->value);

        if ($fails) {
            $this->assertSame('Custom transport unavailable.', $result->details);
        }
    }

    /**
     * Provide successful and failing transport probes.
     */
    public static function customConnectionOutcomes(): array
    {
        return [[false], [true]];
    }

    public function testReportsInvalidPdoOptionsInsteadOfReplacingThem(): void
    {
        config([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'options' => false],
        ]);

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('array_diff_key', $result->details);
    }

    #[DataProvider('splitConnections')]
    public function testProbesSelectableReadAndWriteRecords(string $suffix, bool $unreachable, array $expectedDatabases): void
    {
        config([
            'database.default' => 'testing' . $suffix,
            'database.connections.testing' => [
                'driver' => 'sqlite', 'database' => 'base',
                'options' => [PDO::ATTR_TIMEOUT => 7],
                'write' => ['database' => 'writer'],
                'read' => [
                    ['database' => 'reader-one'],
                    ['database' => 'reader-two', 'options' => [PDO::ATTR_CASE => PDO::CASE_LOWER]],
                ],
            ],
        ]);
        $probe = (object) ['configurations' => []];
        $this->app->bind('db.connector.sqlite', fn (): ConnectorInterface => new class($probe, $unreachable) implements ConnectorInterface {
            /**
             * Capture selected endpoint configurations.
             */
            public function __construct(private stdClass $probe, private bool $unreachable)
            {
            }

            /**
             * Connect each healthy record using a local database.
             */
            public function connect(array $config): PDO
            {
                $this->probe->configurations[$config['database']] = $config;
                if ($this->unreachable && $config['database'] === 'reader-two') {
                    throw new RuntimeException('Replica unavailable.');
                }

                return new PDO('sqlite::memory:');
            }
        });

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame($unreachable && $suffix !== '::write' ? 'fail' : 'pass', $result->status->value, $result->details ?? '');
        $this->assertSame($expectedDatabases, array_keys($probe->configurations));
        foreach ($probe->configurations as $database => $configuration) {
            $this->assertSame($database === 'reader-two' ? 2 : 7, $configuration['options'][PDO::ATTR_TIMEOUT]);
        }
        if ($result->status->value === 'fail') {
            $this->assertSame('[read record 2] Replica unavailable.', $result->details);
        }
    }

    /**
     * Provide default and explicit role selections with a failing replica.
     */
    public static function splitConnections(): array
    {
        return [
            'healthy split' => ['', false, ['writer', 'reader-one', 'reader-two']],
            'unreachable replica' => ['', true, ['writer', 'reader-one', 'reader-two']],
            'explicit read' => ['::read', true, ['reader-one', 'reader-two']],
            'explicit write' => ['::write', true, ['writer']],
        ];
    }

    #[DataProvider('readExtensionSelections')]
    public function testReadRecordExtensionsFollowTheRequestedConnectionRole(string $suffix): void
    {
        config([
            'database.default' => 'testing' . $suffix,
            'database.connections.testing' => [
                'driver' => 'sqlite', 'database' => ':memory:',
                'write' => [],
                'read' => ['driver' => 'replica', 'options' => 'transport-owned'],
            ],
        ]);
        $custom = m::mock(Connection::class);
        if ($suffix === '::read') {
            $custom->expects('getServerVersion')->andReturn('1.0');
            $custom->expects('disconnect');
        }
        DB::extend('replica', function (array $configuration) use ($custom, $suffix): Connection {
            $this->assertSame('::read', $suffix);
            $this->assertSame('transport-owned', $configuration['options']);

            return $custom;
        });

        $result = (new DatabaseConnectionIsReachable)->check();

        $this->assertSame($suffix === '::read' ? 'pass' : 'fail', $result->status->value);
        if ($suffix === '') {
            $this->assertSame('[read record 1] Unsupported driver [replica].', $result->details);
        }
    }

    /**
     * Provide the paths that resolve read-record extensions differently.
     */
    public static function readExtensionSelections(): array
    {
        return [[''], ['::read']];
    }
}
