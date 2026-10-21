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
            'database.connections.analytics' => ['driver' => 'custom'],
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
}
