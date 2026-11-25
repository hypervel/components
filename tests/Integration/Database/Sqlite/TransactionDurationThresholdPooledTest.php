<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use RuntimeException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class TransactionDurationThresholdPooledTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected static string $primaryDatabasePath;

    protected static string $analyticsDatabasePath;

    protected static string $databaseDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$databaseDirectory = ParallelTesting::tempDir('TransactionDurationThresholdPooledTest');
        $files = new Filesystem;
        $files->deleteDirectory(self::$databaseDirectory);
        $files->ensureDirectoryExists(self::$databaseDirectory);
        self::$primaryDatabasePath = self::$databaseDirectory . '/primary.sqlite';
        self::$analyticsDatabasePath = self::$databaseDirectory . '/analytics.sqlite';

        foreach ([self::$primaryDatabasePath, self::$analyticsDatabasePath] as $path) {
            touch($path);
        }
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('app.stdout_log.level', []);
        $config->set('database.default', 'pool_test');
        $config->set('database.connections.pool_test', $this->connectionConfig(self::$primaryDatabasePath));
        $config->set('database.connections.analytics', $this->connectionConfig(self::$analyticsDatabasePath));
    }

    public function testManagerTransactionDurationHandlersTimeEachPooledBorrower(): void
    {
        $calls = [];

        DB::whenTransactionRunsLongerThan(10, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event, float $duration) use (&$calls) {
            $calls[] = [$connection, $event, $duration];
        });

        $slowConnection = null;

        run(function () use (&$slowConnection) {
            parallel([
                function () use (&$slowConnection) {
                    $slowConnection = DB::connection();
                    DB::transaction(fn () => usleep(20000));
                },
                function () {
                    DB::transaction(function () {
                        usleep(1000);
                    });
                },
                function () {
                    DB::connection('analytics')->transaction(fn () => usleep(20000));
                },
            ]);
        });

        $this->assertCount(1, $calls);
        $this->assertSame($slowConnection, $calls[0][0]);
        $this->assertInstanceOf(TransactionCommitted::class, $calls[0][1]);
        $this->assertSame($slowConnection, $calls[0][1]->connection);
        $this->assertGreaterThan(10, $calls[0][2]);

        run(function () {
            try {
                DB::transaction(function () {
                    usleep(20000);

                    throw new RuntimeException('Rolled back.');
                });
            } catch (RuntimeException) {
            }
        });

        $this->assertCount(2, $calls);
        $this->assertInstanceOf(TransactionRolledBack::class, $calls[1][1]);
        $this->assertGreaterThan(10, $calls[1][2]);
    }

    public function testManagerTransactionDurationHandlersTimeTransactionsRolledBackOnRelease(): void
    {
        $events = [];

        DB::whenTransactionRunsLongerThan(10, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event) use (&$events) {
            $events[] = $event::class;
        });

        run(function () {
            DB::beginTransaction();
            usleep(20000);
        });

        $this->assertSame([TransactionRolledBack::class], $events);
    }

    public function testManagerTransactionDurationHandlersTimeTransactionsRunByCallbacksAndListeners(): void
    {
        $durations = [];
        $listenerRan = false;

        Event::listen(TransactionRolledBack::class, function () use (&$listenerRan) {
            if (! $listenerRan) {
                $listenerRan = true;
                DB::transaction(fn () => null);
            }
        });

        DB::whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event, float $duration) use (&$durations) {
            $durations[] = [$event::class, $duration];
        });

        run(function () {
            DB::transaction(function () {
                DB::afterCommit(function () {
                    usleep(20000);
                    DB::transaction(fn () => null);
                });
            });

            DB::beginTransaction();
            usleep(20000);
            DB::rollBack();
        });

        $this->assertSame(
            [TransactionCommitted::class, TransactionCommitted::class, TransactionCommitted::class, TransactionRolledBack::class],
            array_column($durations, 0)
        );
        $this->assertGreaterThanOrEqual(20, $durations[1][1]);
        $this->assertGreaterThanOrEqual(20, $durations[3][1]);
    }

    public function testManagerTransactionDurationHandlersRequireTheDeliveredCompletionEvent(): void
    {
        $calls = 0;

        DB::whenTransactionRunsLongerThan(-1, function () use (&$calls) {
            ++$calls;
        });

        run(function () {
            Event::defer(fn () => DB::transaction(fn () => null), [TransactionCommitted::class]);
        });

        $this->assertSame(1, $calls);

        Event::fake([TransactionCommitted::class]);

        run(function () {
            DB::transaction(fn () => null);
        });

        Event::assertDispatched(TransactionCommitted::class);
        $this->assertSame(1, $calls);
    }

    public function testManagerTransactionDurationHandlersMatchTheRegisteredConnectionRole(): void
    {
        $calls = [];

        DB::whenTransactionRunsLongerThan(-1, function (Connection $connection) use (&$calls) {
            $calls[] = ['pool_test', $connection->getNameWithReadWriteType()];
        });

        DB::usingConnection('pool_test::write', function () use (&$calls) {
            DB::whenTransactionRunsLongerThan(-1, function (Connection $connection) use (&$calls) {
                $calls[] = ['pool_test::write', $connection->getNameWithReadWriteType()];
            });
        });

        run(function () {
            DB::transaction(fn () => null);
            DB::connection('pool_test::write')->transaction(fn () => null);
        });

        $this->assertSame([
            ['pool_test', 'pool_test'],
            ['pool_test', 'pool_test::write'],
            ['pool_test::write', 'pool_test::write'],
        ], $calls);
    }

    protected function connectionConfig(string $databasePath): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'pool' => [
                'min_retained_connections' => 1,
                'max_connections' => 5,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 60.0,
                'testing_enabled' => true,
            ],
        ];
    }
}
