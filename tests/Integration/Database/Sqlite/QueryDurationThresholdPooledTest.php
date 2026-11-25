<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class QueryDurationThresholdPooledTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected static string $primaryDatabasePath;

    protected static string $analyticsDatabasePath;

    protected static string $databaseDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$databaseDirectory = ParallelTesting::tempDir('QueryDurationThresholdPooledTest');
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
        $config->set('database.default', 'pool_test');
        $config->set('database.connections.pool_test', $this->connectionConfig(self::$primaryDatabasePath));
        $config->set('database.connections.analytics', $this->connectionConfig(self::$analyticsDatabasePath));
    }

    public function testManagerQueryDurationHandlersSurvivePooledConnectionRelease(): void
    {
        $calls = 0;

        DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
            ++$calls;
        });

        run(function () {
            DB::select('SELECT 1');
        });

        run(function () {
            DB::select('SELECT 1');
        });

        $this->assertSame(2, $calls);
    }

    public function testManagerQueryDurationHandlersOnlyRunOncePerCoroutineUntilReset(): void
    {
        $calls = 0;

        DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
            ++$calls;
        });

        run(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');

            DB::allowQueryDurationHandlersToRunAgain();

            DB::select('SELECT 1');
        });

        $this->assertSame(2, $calls);
    }

    public function testManagerQueryDurationHandlersOnlyRunOnceWhenHandlerRunsAnotherQuery(): void
    {
        $calls = 0;

        DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
            ++$calls;

            DB::select('SELECT 2');
        });

        run(function () {
            DB::select('SELECT 1');
            DB::select('SELECT 1');
        });

        $this->assertSame(1, $calls);
    }

    public function testManagerQueryDurationHandlerFiredStateIsCoroutineIsolated(): void
    {
        $calls = 0;

        DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
            ++$calls;
        });

        run(function () {
            parallel([
                function () {
                    DB::select('SELECT 1');
                    usleep(1000);
                    DB::select('SELECT 1');
                },
                function () {
                    DB::select('SELECT 1');
                    usleep(1000);
                    DB::select('SELECT 1');
                },
            ]);
        });

        $this->assertSame(2, $calls);
    }

    public function testManagerQueryDurationHandlersAreScopedToTheEffectiveConnection(): void
    {
        $connections = [];

        DB::whenQueryingForLongerThan(-1, function (Connection $connection) use (&$connections) {
            $connections[] = $connection->getName();
        });

        run(function () {
            DB::connection('analytics')->select('SELECT 1');
        });

        $this->assertSame([], $connections);

        run(function () {
            DB::select('SELECT 1');
        });

        $this->assertSame(['pool_test'], $connections);
    }

    public function testUsingConnectionTargetsManagerQueryDurationHandlersToThatConnection(): void
    {
        $connections = [];

        DB::usingConnection('analytics', function () use (&$connections) {
            DB::whenQueryingForLongerThan(-1, function (Connection $connection, QueryExecuted $event) use (&$connections) {
                $connections[] = [$connection->getName(), $event->connectionName];
            });
        });

        run(function () {
            DB::select('SELECT 1');
        });

        $this->assertSame([], $connections);

        run(function () {
            DB::connection('analytics')->select('SELECT 1');
        });

        $this->assertSame([
            ['analytics', 'analytics'],
        ], $connections);
    }

    public function testManagerQueryDurationHandlersMatchTheRegisteredConnectionRole(): void
    {
        $calls = [];

        DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
            $calls[] = 'pool_test';
        });

        DB::usingConnection('pool_test::write', function () use (&$calls) {
            DB::whenQueryingForLongerThan(-1, function () use (&$calls) {
                $calls[] = 'pool_test::write';
            });
        });

        run(function () {
            DB::select('SELECT 1');
            DB::allowQueryDurationHandlersToRunAgain();
            DB::connection('pool_test::write')->select('SELECT 1');
            DB::allowQueryDurationHandlersToRunAgain();
            DB::connection('pool_test::write')->select('SELECT 1');
            DB::usingConnection('pool_test::write', fn () => DB::allowQueryDurationHandlersToRunAgain());
            DB::connection('pool_test::write')->select('SELECT 1');
        });

        $this->assertSame([
            'pool_test',
            'pool_test',
            'pool_test::write',
            'pool_test',
            'pool_test',
            'pool_test::write',
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
