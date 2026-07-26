<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PooledConnection;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\SessionConfigurator;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testing\ParallelTesting;
use PDO;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

/**
 * Tests coroutine safety of database components.
 *
 * These tests verify that Model::unguarded(), DatabaseManager::usingConnection(),
 * and Connection::beforeExecuting() properly isolate state between coroutines.
 */
class ConnectionCoroutineSafetyTest extends DatabaseTestCase
{
    protected static string $databaseDirectory;

    protected static string $readPath;

    protected static string $writePath;

    protected static string $sessionPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $filesystem = new Filesystem;
        static::$databaseDirectory = ParallelTesting::tempDir('ConnectionCoroutineSafetyTest');
        $filesystem->ensureDirectoryExists(static::$databaseDirectory);

        static::$readPath = static::$databaseDirectory . '/read.sqlite';
        static::$writePath = static::$databaseDirectory . '/write.sqlite';
        static::$sessionPath = static::$databaseDirectory . '/session.sqlite';
        touch(static::$readPath);
        touch(static::$writePath);
        touch(static::$sessionPath);
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(static::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('app.stdout_log.level', []);

        $app->make('config')->set('database.connections.sqlite_readwrite_pool', [
            'driver' => 'sqlite',
            'read' => [
                'database' => static::$readPath,
            ],
            'write' => [
                'database' => static::$writePath,
            ],
            'pool' => [
                'testing_enabled' => true,
                'max_connections' => 5,
                'heartbeat' => -1,
            ],
        ]);

        $app->make('config')->set('database.connections.session_context_pool', [
            'driver' => 'sqlite',
            'database' => static::$sessionPath,
            'pool' => [
                'testing_enabled' => true,
                'min_connections' => 1,
                'max_connections' => 1,
                'heartbeat' => -1,
            ],
        ]);
    }

    protected function afterRefreshingDatabase(): void
    {
        Schema::create('tmp_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        UnguardedTestUser::$eventLog = [];
        Model::reguard();
    }

    public function testUnguardedDisablesGuardingWithinCallback(): void
    {
        $this->assertFalse(Model::isUnguarded());

        Model::unguarded(function () {
            $this->assertTrue(Model::isUnguarded());
        });

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedRestoresStateAfterException(): void
    {
        $this->assertFalse(Model::isUnguarded());

        try {
            Model::unguarded(function () {
                $this->assertTrue(Model::isUnguarded());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedSupportsNesting(): void
    {
        $this->assertFalse(Model::isUnguarded());

        Model::unguarded(function () {
            $this->assertTrue(Model::isUnguarded());

            Model::unguarded(function () {
                $this->assertTrue(Model::isUnguarded());
            });

            $this->assertTrue(Model::isUnguarded());
        });

        $this->assertFalse(Model::isUnguarded());
    }

    public function testUnguardedIsCoroutineIsolated(): void
    {
        $results = [];
        $channel = new Channel(2);
        $waiter = new WaitGroup;

        $waiter->add(1);
        go(function () use ($channel, $waiter) {
            Model::unguarded(function () use ($channel) {
                $channel->push(['coroutine' => 1, 'unguarded' => Model::isUnguarded()]);
                usleep(50000);
            });
            $waiter->done();
        });

        $waiter->add(1);
        go(function () use ($channel, $waiter) {
            usleep(10000);
            $channel->push(['coroutine' => 2, 'unguarded' => Model::isUnguarded()]);
            $waiter->done();
        });

        $waiter->wait();
        $channel->close();

        while (($result = $channel->pop()) !== false) {
            $results[$result['coroutine']] = $result['unguarded'];
        }

        $this->assertTrue($results[1], 'Coroutine 1 should be unguarded');
        $this->assertFalse($results[2], 'Coroutine 2 should NOT be unguarded (isolated context)');
    }

    public function testUsingConnectionChangesDefaultWithinCallback(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $testConnection = 'sqlite';

        $manager->usingConnection($testConnection, function () use ($manager, $testConnection) {
            $this->assertSame($testConnection, $manager->getDefaultConnection());
        });

        $this->assertSame($originalDefault, $manager->getDefaultConnection());
    }

    public function testUsingConnectionRestoresStateAfterException(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();
        $testConnection = 'sqlite';

        try {
            $manager->usingConnection($testConnection, function () use ($manager, $testConnection) {
                $this->assertSame($testConnection, $manager->getDefaultConnection());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame($originalDefault, $manager->getDefaultConnection());
    }

    public function testUsingConnectionIsCoroutineIsolated(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();
        $testConnection = 'sqlite';

        $results = [];
        $channel = new Channel(2);
        $waiter = new WaitGroup;

        $waiter->add(1);
        go(function () use ($channel, $waiter, $manager, $testConnection) {
            $manager->usingConnection($testConnection, function () use ($channel, $manager) {
                $channel->push(['coroutine' => 1, 'connection' => $manager->getDefaultConnection()]);
                usleep(50000);
            });
            $waiter->done();
        });

        $waiter->add(1);
        go(function () use ($channel, $waiter, $manager) {
            usleep(10000);
            $channel->push(['coroutine' => 2, 'connection' => $manager->getDefaultConnection()]);
            $waiter->done();
        });

        $waiter->wait();
        $channel->close();

        while (($result = $channel->pop()) !== false) {
            $results[$result['coroutine']] = $result['connection'];
        }

        $this->assertSame($testConnection, $results[1], 'Coroutine 1 should see overridden connection');
        $this->assertSame($originalDefault, $results[2], 'Coroutine 2 should see original connection (isolated)');
    }

    public function testUsingConnectionAffectsDbConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $connectionBefore = DB::connection();
        $this->assertSame($originalDefault, $connectionBefore->getName());

        $testConnection = 'sqlite';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            $connection = DB::connection();
            $this->assertSame(
                $testConnection,
                $connection->getName(),
                'DB::connection() should return the usingConnection override'
            );
        });

        $connectionAfter = DB::connection();
        $this->assertSame($originalDefault, $connectionAfter->getName());
    }

    public function testUsingConnectionAffectsSchemaConnection(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);
        $originalDefault = $manager->getDefaultConnection();

        $testConnection = 'sqlite';

        $manager->usingConnection($testConnection, function () use ($testConnection) {
            $schemaBuilder = Schema::connection();
            $connectionName = $schemaBuilder->getConnection()->getName();

            $this->assertSame(
                $testConnection,
                $connectionName,
                'Schema::connection() should return schema builder for usingConnection override'
            );
        });
    }

    public function testUsingConnectionAffectsConnectionResolver(): void
    {
        /** @var DatabaseManager $manager */
        $manager = $this->app->make(DatabaseManager::class);

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->app->make('db.resolver');

        $originalDefault = $manager->getDefaultConnection();
        $testConnection = 'sqlite';

        $this->assertSame($originalDefault, $resolver->getDefaultConnection());

        $manager->usingConnection($testConnection, function () use ($resolver, $testConnection) {
            $this->assertSame(
                $testConnection,
                $resolver->getDefaultConnection(),
                'ConnectionResolver::getDefaultConnection() should respect usingConnection override'
            );

            $connection = $resolver->connection();
            $this->assertSame(
                $testConnection,
                $connection->getName(),
                'ConnectionResolver::connection() should return usingConnection override'
            );
        });

        $this->assertSame($originalDefault, $resolver->getDefaultConnection());
    }

    public function testBeforeExecutingCallbackIsCalled(): void
    {
        $called = false;
        $capturedQuery = null;

        /** @var Connection $connection */
        $connection = DB::connection();
        $connection->beforeExecuting(function ($query) use (&$called, &$capturedQuery) {
            $called = true;
            $capturedQuery = $query;
        });

        $connection->select('SELECT 1');

        $this->assertTrue($called);
        $this->assertSame('SELECT 1', $capturedQuery);
    }

    public function testClearBeforeExecutingCallbacksExists(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        $called = false;
        $connection->beforeExecuting(function () use (&$called) {
            $called = true;
        });

        $this->assertTrue(method_exists($connection, 'clearBeforeExecutingCallbacks'));

        $connection->clearBeforeExecutingCallbacks();

        $connection->select('SELECT 1');
        $this->assertFalse($called);
    }

    public function testConnectionTracksErrorCount(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        $this->assertTrue(method_exists($connection, 'getErrorCount'));

        $initialCount = $connection->getErrorCount();

        try {
            $connection->select('SELECT * FROM nonexistent_table_xyz');
        } catch (Throwable) {
            // Expected
        }

        $this->assertGreaterThan($initialCount, $connection->getErrorCount());
    }

    public function testPooledConnectionHasEventDispatcher(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        $dispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($dispatcher, 'Pooled connection should have event dispatcher configured');
    }

    public function testPooledConnectionHasTransactionManager(): void
    {
        /** @var Connection $connection */
        $connection = DB::connection();

        $manager = $connection->getTransactionManager();
        $this->assertNotNull($manager, 'Pooled connection should have transaction manager configured');
    }

    public function testSessionConfiguratorReadsCoroutineContextOnEachPooledHandOut(): void
    {
        $configurator = new CoroutineSessionConfigurator('session_context_pool');
        Connection::configureSessionUsing($configurator);
        $pool = new DbPool($this->app, 'session_context_pool');
        $firstFinished = new Channel(1);

        try {
            [$firstValue, $secondValue] = parallel([
                function () use ($pool, $firstFinished): int {
                    CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '101');

                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $pool->get();

                    try {
                        return (int) $pooledConnection->getConnection()
                            ->selectOne('PRAGMA user_version')
                            ->user_version;
                    } finally {
                        $pooledConnection->release();
                        $firstFinished->push(true);
                    }
                },
                function () use ($pool, $firstFinished): int {
                    $firstFinished->pop();
                    CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '202');

                    /** @var PooledConnection $pooledConnection */
                    $pooledConnection = $pool->get();

                    try {
                        return (int) $pooledConnection->getConnection()
                            ->selectOne('PRAGMA user_version')
                            ->user_version;
                    } finally {
                        $pooledConnection->release();
                    }
                },
            ]);

            CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '202');
            /** @var PooledConnection $matchingPooledConnection */
            $matchingPooledConnection = $pool->get();

            try {
                $matchingValue = (int) $matchingPooledConnection->getConnection()
                    ->selectOne('PRAGMA user_version')
                    ->user_version;
            } finally {
                $matchingPooledConnection->release();
            }

            $this->assertSame(101, $firstValue);
            $this->assertSame(202, $secondValue);
            $this->assertSame(202, $matchingValue);
            $this->assertSame(['101', '202'], $configurator->appliedStates);
            $this->assertSame(3, $configurator->stateCalls);
            $this->assertSame(2, $configurator->applyCalls);
        } finally {
            $pool->close();
        }
    }

    public function testOverlappingConfigurationOfSharedPdoFailsClosedForBothCallers(): void
    {
        $connectionName = 'session_shared_connection';
        $configurator = new CoroutineSessionConfigurator($connectionName);
        Connection::configureSessionUsing($configurator);
        CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '0');
        $pdo = new PDO('sqlite::memory:');
        $config = ['name' => $connectionName];
        $firstConnection = new Connection($pdo, ':memory:', '', $config);
        $secondConnection = new Connection($pdo, ':memory:', '', $config);
        $configurationStarted = new Channel(1);
        $resumeConfiguration = new Channel(1);
        $configurator->blockedState = '101';
        $configurator->configurationStarted = $configurationStarted;
        $configurator->resumeConfiguration = $resumeConfiguration;

        $firstConnection->getPdo();

        [$firstFailure, $secondFailure] = parallel([
            function () use ($firstConnection): string {
                CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '101');

                try {
                    $firstConnection->getPdo();

                    return 'no failure';
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }
            },
            function () use ($secondConnection, $configurationStarted, $resumeConfiguration): string {
                $configurationStarted->pop();
                CoroutineContext::set(CoroutineSessionConfigurator::CONTEXT_KEY, '202');

                try {
                    $secondConnection->getPdo();

                    return 'no failure';
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                } finally {
                    $resumeConfiguration->push(true);
                }
            },
        ]);

        $this->assertSame('Database session state became unknown during configuration.', $firstFailure);
        $this->assertSame('Reentrant database session configuration is not allowed.', $secondFailure);
        $this->assertSame(['0', '101'], $configurator->appliedStates);
        $this->assertSame(2, $configurator->applyCalls);
    }

    public function testWriteSuffixDoesNotForcePlainConnectionReadsInAnotherCoroutine(): void
    {
        $this->seedReadWritePoolMarkers();

        [$writeValue, $plainValue] = parallel([
            function () {
                $value = DB::connection('sqlite_readwrite_pool::write')
                    ->selectOne('select value from markers')
                    ->value;

                usleep(5000);

                return $value;
            },
            function () {
                usleep(1000);

                return DB::connection('sqlite_readwrite_pool')
                    ->selectOne('select value from markers')
                    ->value;
            },
        ]);

        $this->assertSame('write', $writeValue);
        $this->assertSame('read', $plainValue);
    }

    public function testWriteSuffixAndPlainConnectionUseSeparateCoroutineContextEntries(): void
    {
        $this->seedReadWritePoolMarkers();

        $write = DB::connection('sqlite_readwrite_pool::write');
        $plain = DB::connection('sqlite_readwrite_pool');

        $this->assertNotSame($write, $plain);
        $this->assertSame($write, DB::connection('sqlite_readwrite_pool::write'));
        $this->assertSame($plain, DB::connection('sqlite_readwrite_pool'));
        $this->assertSame('write', $write->selectOne('select value from markers')->value);
        $this->assertSame('read', $plain->selectOne('select value from markers')->value);
    }

    public function testReadSuffixAndPlainConnectionUseSeparateCoroutineContextEntries(): void
    {
        $this->seedReadWritePoolMarkers();

        $read = DB::connection('sqlite_readwrite_pool::read');
        $plain = DB::connection('sqlite_readwrite_pool');

        $this->assertNotSame($read, $plain);
        $this->assertSame($read, DB::connection('sqlite_readwrite_pool::read'));
        $this->assertSame($plain, DB::connection('sqlite_readwrite_pool'));
        $this->assertSame('read', $read->selectOne('select value from markers')->value);
        $this->assertSame('read', $plain->selectOne('select value from markers')->value);
    }

    protected function seedReadWritePoolMarkers(): void
    {
        foreach (['sqlite_readwrite_pool::read' => 'read', 'sqlite_readwrite_pool::write' => 'write'] as $connectionName => $value) {
            $connection = DB::connection($connectionName);
            $connection->statement('drop table if exists markers');
            $connection->statement('create table markers (value varchar not null)');
            $connection->insert('insert into markers (value) values (?)', [$value]);
        }
    }
}

class UnguardedTestUser extends Model
{
    protected ?string $table = 'tmp_users';

    protected array $fillable = ['name', 'email'];

    public static array $eventLog = [];
}

class CoroutineSessionConfigurator implements SessionConfigurator
{
    public const CONTEXT_KEY = '__database.session_configurator_test';

    public int $stateCalls = 0;

    public int $applyCalls = 0;

    /**
     * @var string[]
     */
    public array $appliedStates = [];

    public ?string $blockedState = null;

    public ?Channel $configurationStarted = null;

    public ?Channel $resumeConfiguration = null;

    public function __construct(
        private readonly string $connectionName,
    ) {
    }

    public function state(Connection $connection): ?string
    {
        ++$this->stateCalls;

        return $connection->getName() === $this->connectionName
            ? CoroutineContext::get(self::CONTEXT_KEY, '0')
            : null;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        ++$this->applyCalls;
        $this->appliedStates[] = $state;

        if ($state === $this->blockedState) {
            $this->configurationStarted?->push(true);
            $this->resumeConfiguration?->pop();
        }

        $pdo->exec('PRAGMA user_version = ' . (int) $state);
    }
}
