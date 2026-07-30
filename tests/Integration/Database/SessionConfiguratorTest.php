<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\StatementPrepared;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use InvalidArgumentException;
use PDO;

class SessionConfiguratorTest extends DatabaseTestCase
{
    private const CONNECTION_NAME = 'session_configurator_test';

    private DbPool $sessionPool;

    private CrossDriverSessionConfigurator $configurator;

    private ?string $sqliteDirectory = null;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $defaultConnection = $config->get('database.default');
        $connectionConfig = $config->get("database.connections.{$defaultConnection}");

        if (($connectionConfig['driver'] ?? null) === 'sqlite') {
            $filesystem = new Filesystem;
            $this->sqliteDirectory = ParallelTesting::tempDir('SessionConfiguratorTest');
            $filesystem->deleteDirectory($this->sqliteDirectory);
            $filesystem->ensureDirectoryExists($this->sqliteDirectory);
            $connectionConfig['database'] = $this->sqliteDirectory . '/session.sqlite';
            touch($connectionConfig['database']);
        }

        $connectionConfig['pool'] = [
            'testing_enabled' => true,
            'min_connections' => 1,
            'max_connections' => 1,
            'heartbeat' => -1,
        ];

        $config->set('database.connections.' . self::CONNECTION_NAME, $connectionConfig);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurator = new CrossDriverSessionConfigurator(self::CONNECTION_NAME, $this->driver);
        Connection::configureSessionUsing($this->configurator);
        $this->sessionPool = new DbPool($this->app, self::CONNECTION_NAME);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->sessionPool)) {
                $this->sessionPool->close();
            }
        } finally {
            parent::tearDown();

            if ($this->sqliteDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->sqliteDirectory);
            }
        }
    }

    public function testFirstUseMatchingStateAndChangedStateHaveExactApplyCounts(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();

            $this->assertSame('101', $this->configurator->readThrough($connection));
            $this->assertSame(1, $this->configurator->stateCalls);
            $this->assertSame(1, $this->configurator->applyCalls);

            $this->assertSame('101', $this->configurator->readThrough($connection));
            $this->assertSame(2, $this->configurator->stateCalls);
            $this->assertSame(1, $this->configurator->applyCalls);

            $this->configurator->desiredState = '202';

            $this->assertSame('202', $this->configurator->readThrough($connection));
            $this->assertSame(3, $this->configurator->stateCalls);
            $this->assertSame(2, $this->configurator->applyCalls);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testCleanReleasePreservesMatchingMemoAndChangedStateAppliesOnReborrow(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $firstPooledConnection = $pooledConnection;
            $firstConnection = $firstPooledConnection->getConnection();
            $firstPdo = $firstConnection->getPdo();
            $this->assertSame('101', $this->configurator->read($firstPdo));
            $pooledConnection->release();
            $pooledConnection = null;

            $pooledConnection = $this->borrow();
            $secondConnection = $pooledConnection->getConnection();

            $this->assertSame($firstPooledConnection, $pooledConnection);
            $this->assertSame($firstConnection, $secondConnection);
            $this->assertSame($firstPdo, $secondConnection->getPdo());
            $this->assertSame(1, $this->configurator->applyCalls);

            $pooledConnection->release();
            $pooledConnection = null;
            $this->configurator->desiredState = '202';
            $pooledConnection = $this->borrow();
            $secondConnection = $pooledConnection->getConnection();

            $this->assertSame('202', $this->configurator->readThrough($secondConnection));
            $this->assertSame(2, $this->configurator->applyCalls);
        } finally {
            $pooledConnection?->release();
        }
    }

    public function testFullRollbackConservativelyInvalidatesAndReappliesState(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $connection->beginTransaction();
            $this->assertSame('101', $this->configurator->readThrough($connection));
            $this->assertSame(1, $this->configurator->applyCalls);

            $connection->rollBack();

            $this->assertSame('101', $this->configurator->readThrough($connection));
            $this->assertSame(2, $this->configurator->applyCalls);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testPublicPdoHandOutSynchronizesAndRawAccessDoesNot(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();

            $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
            $this->assertSame(0, $this->configurator->stateCalls);
            $this->assertSame(0, $this->configurator->applyCalls);

            $pdo = $connection->getPdo();
            $this->assertSame('101', $this->configurator->read($pdo));
            $this->assertSame(1, $this->configurator->applyCalls);

            $this->configurator->desiredState = '202';
            $this->assertSame('101', $this->configurator->read($connection->getRawPdo()));
            $this->assertSame(1, $this->configurator->applyCalls);

            $this->assertSame('202', $this->configurator->read($connection->getPdo()));
            $this->assertSame(2, $this->configurator->applyCalls);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testConfigurationSqlDoesNotCreateIndependentFrameworkInstrumentation(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $preparedStatements = 0;
            $executedQueries = 0;
            $events = $connection->getEventDispatcher();
            $events?->listen(StatementPrepared::class, static function () use (&$preparedStatements): void {
                ++$preparedStatements;
            });
            $events?->listen(QueryExecuted::class, static function () use (&$executedQueries): void {
                ++$executedQueries;
            });
            $connection->enableQueryLog();

            $this->assertSame('101', $this->configurator->readThrough($connection));

            $this->assertSame(1, $this->configurator->applyCalls);
            $this->assertSame(1, $preparedStatements);
            $this->assertSame(1, $executedQueries);
            $this->assertCount(1, $connection->getQueryLog());
        } finally {
            $pooledConnection->release();
        }
    }

    private function borrow(): PooledConnection
    {
        /** @var PooledConnection $pooledConnection */
        return $this->sessionPool->get();
    }
}

class CrossDriverSessionConfigurator implements SessionConfigurator
{
    public string $desiredState = '101';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    public function __construct(
        private readonly string $connectionName,
        private readonly string $driver,
    ) {
    }

    public function state(Connection $connection): ?string
    {
        if ($connection->getName() !== $this->connectionName) {
            return null;
        }

        ++$this->stateCalls;

        return $this->desiredState;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        ++$this->applyCalls;

        match ($this->driver) {
            'pgsql' => $this->execute($pdo, "select set_config('hypervel_test.context', ?, false)", $state),
            'mysql', 'mariadb' => $this->execute($pdo, 'set @hypervel_test_context := ?', $state),
            'sqlite' => $this->configureSqlite($pdo, $state),
            default => throw new InvalidArgumentException("Unsupported test database driver [{$this->driver}]."),
        };
    }

    public function readThrough(Connection $connection): string
    {
        return match ($this->driver) {
            'pgsql' => (string) $connection->scalar(
                "select current_setting('hypervel_test.context', true)",
                useReadPdo: false
            ),
            'mysql', 'mariadb' => (string) $connection->scalar(
                'select @hypervel_test_context',
                useReadPdo: false
            ),
            'sqlite' => (string) $connection->scalar('PRAGMA cache_size', useReadPdo: false),
            default => throw new InvalidArgumentException("Unsupported test database driver [{$this->driver}]."),
        };
    }

    public function read(PDO|Closure|null $pdo): string
    {
        if (! $pdo instanceof PDO) {
            throw new InvalidArgumentException('Expected an already-resolved PDO.');
        }

        $statement = $pdo->query(match ($this->driver) {
            'pgsql' => "select current_setting('hypervel_test.context', true)",
            'mysql', 'mariadb' => 'select @hypervel_test_context',
            'sqlite' => 'PRAGMA cache_size',
            default => throw new InvalidArgumentException("Unsupported test database driver [{$this->driver}]."),
        });

        if ($statement === false) {
            throw new InvalidArgumentException('Unable to read the native database session state.');
        }

        try {
            return (string) $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
    }

    private function execute(PDO $pdo, string $query, string $state): void
    {
        $statement = $pdo->prepare($query);
        $statement->execute([$state]);
        $statement->closeCursor();
    }

    private function configureSqlite(PDO $pdo, string $state): void
    {
        $value = filter_var($state, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new InvalidArgumentException('SQLite session test state must be an integer.');
        }

        $pdo->exec("PRAGMA cache_size = {$value}");
    }
}
