<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\QueryException;
use Hypervel\Database\SessionConfigurator;
use PDO;
use RuntimeException;

class SessionConfiguratorTest extends PostgresTestCase
{
    private const CONNECTION_NAME = 'postgres_session_configurator_test';

    private DbPool $sessionPool;

    private PostgresSessionConfigurator $configurator;

    /**
     * @var array<string, mixed>
     */
    private array $postgresConfig;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('app.stdout_log.level', []);
        $defaultConnection = $config->get('database.default');
        $this->postgresConfig = $config->get("database.connections.{$defaultConnection}");
        $connectionConfig = $this->postgresConfig;
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

        $this->configurator = new PostgresSessionConfigurator(self::CONNECTION_NAME);
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
        }
    }

    public function testSessionStateEstablishedBeforeTransactionPersistsAfterCommit(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();
            $connection->beginTransaction();
            $connection->commit();

            $this->assertSame('outside', $this->configurator->read($pdo));
            $this->assertSame($pdo, $connection->getPdo());
            $this->assertSame(1, $this->configurator->applyCalls);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testFullRollbackRevertsTransactionalStateAndFrameworkReappliesIt(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();
            $connection->beginTransaction();
            $this->configurator->desiredState = 'inside';

            $this->assertSame('inside', $this->configurator->readThrough($connection));

            $connection->rollBack();

            $this->assertSame('outside', $this->configurator->read($pdo));
            $this->assertSame('inside', $this->configurator->readThrough($connection));
            $this->assertSame(['outside', 'inside', 'inside'], $this->configurator->appliedStates);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testSavepointRollbackRevertsLaterStateAndFrameworkReappliesIt(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();
            $connection->beginTransaction();
            $this->configurator->desiredState = 'before_savepoint';
            $this->assertSame('before_savepoint', $this->configurator->readThrough($connection));
            $connection->beginTransaction();
            $this->configurator->desiredState = 'after_savepoint';
            $this->assertSame('after_savepoint', $this->configurator->readThrough($connection));

            $connection->rollBack(1);

            $this->assertSame('before_savepoint', $this->configurator->read($pdo));
            $this->assertSame('after_savepoint', $this->configurator->readThrough($connection));
            $connection->rollBack();
        } finally {
            $pooledConnection->release();
        }
    }

    public function testRollbackRecoversAnAbortedTransactionWithoutInvokingConfigurator(): void
    {
        $pooledConnection = $this->borrow();

        try {
            $connection = $pooledConnection->getConnection();
            $pdo = $connection->getPdo();
            $connection->beginTransaction();
            $this->configurator->desiredState = 'inside';
            $this->assertSame('inside', $this->configurator->readThrough($connection));

            try {
                $connection->statement('select * from hypervel_missing_session_table');
                $this->fail('Expected query exception was not thrown.');
            } catch (QueryException) {
            }

            $this->configurator->desiredState = 'after_rollback';
            $stateCallsBeforeRollback = $this->configurator->stateCalls;
            $connection->rollBack();

            $this->assertSame($stateCallsBeforeRollback, $this->configurator->stateCalls);
            $this->assertSame('outside', $this->configurator->read($pdo));
            $this->assertSame('after_rollback', $this->configurator->readThrough($connection));
        } finally {
            $pooledConnection->release();
        }
    }

    public function testEmptyStateIsAppliedAsARealValue(): void
    {
        $this->configurator->desiredState = '';
        $pooledConnection = $this->borrow();

        try {
            $this->assertSame('', $this->configurator->readThrough($pooledConnection->getConnection()));
            $this->assertSame(1, $this->configurator->applyCalls);
            $this->assertSame([''], $this->configurator->appliedStates);
        } finally {
            $pooledConnection->release();
        }
    }

    public function testDeadIdlePdoReconnectsWhenConfigurationIsTheFirstFailingSql(): void
    {
        $pooledConnection = $this->borrow();
        $connection = $pooledConnection->getConnection();
        $targetPdo = $connection->getPdo();
        $targetPid = (int) $connection->scalar('select pg_backend_pid()', useReadPdo: false);
        $pooledConnection->release();
        $pooledConnection = null;

        /** @var ConnectionFactory $factory */
        $factory = $this->app->make('db.factory');
        $adminConnection = $factory->make($this->postgresConfig, 'postgres_session_configurator_admin');
        $adminPdo = $adminConnection->getPdo();

        try {
            $statement = $adminPdo->prepare('select pg_terminate_backend(?)');
            $statement->execute([$targetPid]);
            $this->assertTrue((bool) $statement->fetchColumn());
            $statement->closeCursor();
            $this->configurator->desiredState = 'after_reconnect';

            $pooledConnection = $this->borrow();
            $connection = $pooledConnection->getConnection();
            $this->assertSame('after_reconnect', $this->configurator->readThrough($connection));
            $replacementPdo = $connection->getRawPdo();
            $replacementPid = (int) $connection->scalar('select pg_backend_pid()', useReadPdo: false);

            $this->assertInstanceOf(PDO::class, $replacementPdo);
            $this->assertNotSame($targetPdo, $replacementPdo);
            $this->assertNotSame($targetPid, $replacementPid);
            $this->assertSame(3, $this->configurator->applyCalls);
            $this->assertSame(1, $connection->getErrorCount());
        } finally {
            if ($pooledConnection instanceof PooledConnection) {
                $pooledConnection->release();
            }

            $adminConnection->disconnect();
        }
    }

    private function borrow(): PooledConnection
    {
        /** @var PooledConnection $pooledConnection */
        return $this->sessionPool->get();
    }
}

class PostgresSessionConfigurator implements SessionConfigurator
{
    public string $desiredState = 'outside';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    /**
     * @var string[]
     */
    public array $appliedStates = [];

    public function __construct(
        private readonly string $connectionName,
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
        $this->appliedStates[] = $state;
        $statement = $pdo->prepare("select set_config('hypervel_test.context', ?, false)");
        $statement->execute([$state]);
        $statement->closeCursor();
    }

    public function readThrough(Connection $connection): string
    {
        return (string) $connection->scalar(
            "select current_setting('hypervel_test.context', true)",
            useReadPdo: false
        );
    }

    public function read(PDO $pdo): string
    {
        $statement = $pdo->query("select current_setting('hypervel_test.context', true)");

        if ($statement === false) {
            throw new RuntimeException('Unable to read the PostgreSQL session state.');
        }

        try {
            return (string) $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
    }
}
