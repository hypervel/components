<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Connectors\ConnectorInterface;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseReadPoolTest extends TestCase
{
    public function testReadRecordsAreSelectedForEachPhysicalCreationAndReconnect(): void
    {
        config(['database.connections.read_pool' => [
            'driver' => 'pgsql',
            'database' => 'app',
            'read' => [['host' => 'first-reader'], ['host' => 'second-reader']],
            'pool' => ['min_retained_connections' => 0, 'max_connections' => 2],
        ]]);
        $factory = new ReadPoolConnectionFactory($this->app);
        $this->app->instance('db.factory', $factory);
        $hosts = [];
        $connector = m::mock(ConnectorInterface::class);
        $connector->expects('connect')->times(3)->andReturnUsing(static function (array $config) use (&$hosts): PDO {
            $hosts[] = $config['host'];

            return new PDO('sqlite::memory:');
        });
        $this->app->instance('db.connector.pgsql', $connector);
        $pool = new DatabasePool($this->app, 'read_pool::read');
        $first = $second = null;

        try {
            $first = $pool->borrow();
            $second = $pool->borrow();
            $first->getConnection()->getPdo();
            $connection = $second->getConnection();
            $connection->getPdo();
            $connection->reconnect();

            $this->assertSame(['first-reader', 'second-reader', 'first-reader'], $hosts);
            $this->assertSame($connection, $second->getConnection());
            $this->assertSame('first-reader', $connection->getConfig('host'));
        } finally {
            $first?->release();
            $second?->release();
            $pool->close();
        }
    }

    #[DataProvider('readPoolOptions')]
    public function testReadRecordsRequireTheSameEffectivePoolOptions(array $secondOptions, bool $conflicting): void
    {
        config(['database.connections.read_pool_options' => [
            'driver' => 'pgsql',
            'database' => 'app',
            'pool' => ['max_connections' => 5],
            'read' => [
                ['host' => 'first-reader', 'pool' => ['max_connections' => 2, 'connect_timeout' => 1]],
                ['host' => 'second-reader', 'pool' => $secondOptions],
            ],
        ]]);

        if ($conflicting) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageIsOrContains('must use the same effective pool options');
        }

        $pool = new DatabasePool($this->app, 'read_pool_options::read');

        try {
            $this->assertSame(2, $pool->getOptions()->maxConnections);
            $this->assertSame(1.0, $pool->getOptions()->connectTimeout);
        } finally {
            $pool->close();
        }
    }

    /**
     * Provide equivalent and conflicting read pool configurations.
     */
    public static function readPoolOptions(): array
    {
        return [
            'matching normalized values' => [[
                'max_connections' => 2,
                'connect_timeout' => 1.0,
                'wait_timeout' => 3.0,
                'testing_enabled' => true,
            ], false],
            'conflicting capacity' => [['max_connections' => 3, 'connect_timeout' => 1], true],
        ];
    }

    public function testEveryReadRecordIsCheckedForAnInMemorySqliteDatabase(): void
    {
        config(['database.connections.read_pool_memory' => [
            'driver' => 'sqlite',
            'database' => '/unused/write.sqlite',
            'read' => [
                ['database' => '/unused/read.sqlite'],
                ['url' => 'sqlite:///:memory:'],
            ],
        ]]);
        $this->app->instance('db.factory', new ReadPoolConnectionFactory($this->app));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('cannot use a derived read pool for in-memory SQLite');

        new DatabasePool($this->app, 'read_pool_memory::read');
    }
}

class ReadPoolConnectionFactory extends ConnectionFactory
{
    protected int $readSelection = 0;

    /**
     * Select consecutive replicas whenever a new read record is needed.
     */
    protected function getReadWriteConfig(array $config, string $type): array
    {
        return $type === 'read' && isset($config[$type][0])
            ? $config[$type][$this->readSelection++ % count($config[$type])]
            : parent::getReadWriteConfig($config, $type);
    }
}
