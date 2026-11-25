<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class TransactionDurationThresholdPooledTest extends TestCase
{
    /**
     * How early a coroutine sleep can return, since Swoole timers count whole milliseconds.
     */
    protected const float SLEEP_TOLERANCE_MILLISECONDS = 1.0;

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
        $slowCalls = 0;

        DB::whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event, float $duration) use (&$calls) {
            $calls[] = [$connection, $event, $duration];
        });

        DB::whenTransactionRunsLongerThan(60000, function () use (&$slowCalls) {
            ++$slowCalls;
        });

        $borrowers = [];

        run(function () use (&$borrowers) {
            parallel([
                function () use (&$borrowers) {
                    $borrowers[] = DB::connection();
                    DB::transaction(fn () => usleep(20000));
                },
                function () use (&$borrowers) {
                    $borrowers[] = DB::connection();
                    DB::transaction(fn () => usleep(20000));
                },
                function () {
                    DB::connection('analytics')->transaction(fn () => usleep(20000));
                },
            ]);
        });

        $this->assertCount(2, $calls);
        $this->assertNotSame($borrowers[0], $borrowers[1]);
        $this->assertNotSame($calls[0][0], $calls[1][0]);

        foreach ($calls as [$connection, $event, $duration]) {
            $this->assertContains($connection, $borrowers);
            $this->assertInstanceOf(TransactionCommitted::class, $event);
            $this->assertSame($connection, $event->connection);
            $this->assertGreaterThanOrEqual(20 - self::SLEEP_TOLERANCE_MILLISECONDS, $duration);
        }

        run(function () {
            try {
                DB::transaction(function () {
                    usleep(20000);

                    throw new RuntimeException('Rolled back.');
                });
            } catch (RuntimeException) {
            }
        });

        $this->assertCount(3, $calls);
        $this->assertInstanceOf(TransactionRolledBack::class, $calls[2][1]);
        $this->assertGreaterThanOrEqual(20 - self::SLEEP_TOLERANCE_MILLISECONDS, $calls[2][2]);
        $this->assertSame(0, $slowCalls);
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
        $this->assertGreaterThanOrEqual(20 - self::SLEEP_TOLERANCE_MILLISECONDS, $durations[1][1]);
        $this->assertGreaterThanOrEqual(20 - self::SLEEP_TOLERANCE_MILLISECONDS, $durations[3][1]);
    }

    public function testManagerTransactionDurationHandlersMeasureDeferredTransactionsWhenTheyComplete(): void
    {
        $managerDurations = [];
        $connectionDurations = [];

        DB::whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event, float $duration) use (&$managerDurations) {
            $managerDurations[] = $duration;
        });

        run(function () use (&$connectionDurations) {
            DB::connection()->whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event, float $duration) use (&$connectionDurations) {
                $connectionDurations[] = $duration;
            });

            // Deferring every event also delivers the beginning event after the transaction has ended.
            Event::defer(function () {
                DB::transaction(fn () => null);
                usleep(20000);
            });

            Event::defer(function () {
                DB::transaction(fn () => null);
                usleep(20000);
            }, [TransactionCommitted::class]);
        });

        $this->assertCount(2, $managerDurations);
        $this->assertSame($connectionDurations, $managerDurations);
    }

    #[DataProvider('transactionEventProvider')]
    public function testManagerTransactionDurationHandlersRequireDeliveredTransactionEvents(string $fakedEvent): void
    {
        $calls = 0;

        DB::whenTransactionRunsLongerThan(-1, function () use (&$calls) {
            ++$calls;
        });

        Event::fake([$fakedEvent]);

        run(function () {
            DB::transaction(fn () => null);
        });

        Event::assertDispatched($fakedEvent);
        $this->assertSame(0, $calls);
    }

    public static function transactionEventProvider(): array
    {
        return [
            'beginning' => [TransactionBeginning::class],
            'committed' => [TransactionCommitted::class],
        ];
    }

    public function testManagerTransactionDurationHandlersMatchTheRegisteredConnectionRole(): void
    {
        $calls = [];

        DB::whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event) use (&$calls) {
            $calls[] = ['pool_test', $event::class];
        });

        DB::usingConnection('pool_test::write', function () use (&$calls) {
            DB::whenTransactionRunsLongerThan(-1, function (Connection $connection, TransactionCommitted|TransactionRolledBack $event) use (&$calls) {
                $calls[] = ['pool_test::write', $event::class];
            });
        });

        run(function () {
            DB::transaction(fn () => null);
        });

        run(function () {
            DB::connection('pool_test::write')->transaction(fn () => null);
        });

        // The pool reset clears the write type before rolling back the abandoned transaction.
        run(function () {
            DB::connection('pool_test::write')->beginTransaction();
        });

        $this->assertSame([
            ['pool_test', TransactionCommitted::class],
            ['pool_test', TransactionCommitted::class],
            ['pool_test::write', TransactionCommitted::class],
            ['pool_test', TransactionRolledBack::class],
            ['pool_test::write', TransactionRolledBack::class],
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
