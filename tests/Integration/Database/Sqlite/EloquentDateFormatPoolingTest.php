<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Sqlite\EloquentDateFormatPoolingTest;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use UnitEnum;

use function Hypervel\Coroutine\run;

class EloquentDateFormatPoolingTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected static string $databaseDirectory;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$databaseDirectory = ParallelTesting::tempDir('EloquentDateFormatPoolingTest');
        $files = new Filesystem;
        $files->deleteDirectory(self::$databaseDirectory);
        $files->ensureDirectoryExists(self::$databaseDirectory);
        touch(self::$databaseDirectory . '/database.sqlite');
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem)->deleteDirectory(self::$databaseDirectory);

        parent::tearDownAfterClass();
    }

    public function testDateCastsInFreshCoroutinesBorrowNoConnectionOnceThePoolKnowsItsFormat(): void
    {
        run(static fn () => DB::connection('pool_test')->select('select 1'));
        $pool = $this->app->make(PoolManager::class)->pool('pool_test');

        run(function () use ($pool): void {
            $model = new DatedModel;
            $model->setRawAttributes(['published_at' => '2026-01-02 03:04:05']);
            $model->updated_at = CarbonImmutable::parse('2026-01-03 04:05:06');

            $this->assertSame('2026-01-02 03:04:05', $model->published_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-01-03 04:05:06', $model->getAttributes()['updated_at']);
            $this->assertFalse(CoroutineContext::has('__database.connection.pool_test'));
            $this->assertSame(0, $pool->getBorrowedCount());
        });
    }

    public function testDateCastsKeepCustomGrammarsWithinTheirLogicalOwner(): void
    {
        run(function (): void {
            $connection = DB::connection('pool_test');
            $connection->setQueryGrammar(new TimestampGrammar($connection));

            $this->assertSame('U', (new DatedModel)->getDateFormat());
        });

        run(function (): void {
            $model = new DatedModel;
            $model->updated_at = CarbonImmutable::createFromTimestamp(1_767_225_600);

            $this->assertSame('2026-01-01 00:00:00', $model->getAttributes()['updated_at']);
            $this->assertFalse(CoroutineContext::has('__database.connection.pool_test'));
        });
    }

    public function testColdDateReadsReturnOnlyTheirOwnIdleSession(): void
    {
        $manager = $this->app->make(PoolManager::class);
        $pool = $manager->pool('pool_test');
        $otherPool = $manager->pool('other');

        run(function () use ($pool, $otherPool): void {
            $other = DB::connection('other');
            $this->assertSame(1, $otherPool->getBorrowedCount());
            $this->assertNull($pool->recordedDateFormat());

            $this->assertSame('Y-m-d H:i:s', (new DatedModel)->getDateFormat());
            $this->assertSame(0, $pool->getBorrowedCount());
            $this->assertSame(1, $otherPool->getBorrowedCount());
            $this->assertSame($other, DB::connection('other'));

            // Reusing the logical connection after a format-only lookup reacquires a session.
            DB::connection('pool_test')->select('select 1');
            $this->assertSame(1, $pool->getBorrowedCount());
            DB::disconnect('pool_test');
            $this->assertNull($pool->recordedDateFormat());
        });

        run(function () use ($pool): void {
            $this->assertSame('Y-m-d H:i:s', (new DatedModel)->getDateFormat());
            $this->assertSame(0, $pool->getBorrowedCount());
        });
    }

    public function testEstablishedListenersCustomizeOnlyTheirLogicalOwner(): void
    {
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $event->connection->setQueryGrammar(new TimestampGrammar($event->connection));
        });

        run(function (): void {
            $this->assertSame('U', (new DatedModel)->getDateFormat());
            $this->assertSame(0, $this->app->make(PoolManager::class)->pool('pool_test')->getBorrowedCount());
        });

        run(function (): void {
            $this->assertSame('Y-m-d H:i:s', (new DatedModel)->getDateFormat());
            $this->assertFalse(CoroutineContext::has('__database.connection.pool_test'));
        });
    }

    public function testSharedConnectionsRecordListenerAndReturnedGrammarChanges(): void
    {
        DB::extend('pool_test', static fn (array $config): SQLiteConnection => new SQLiteConnection(
            new PDO('sqlite:' . $config['database']),
            $config['database'],
            $config['prefix'],
            $config,
        ));
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $event->connection->setQueryGrammar(new TimestampGrammar($event->connection));
        });
        $pool = $this->app->make(PoolManager::class)->pool('pool_test');
        $this->assertFalse($pool->usesSessionLeases());

        run(function () use ($pool): void {
            $this->assertSame('U', (new DatedModel)->getDateFormat());
            $this->assertSame(1, $pool->getBorrowedCount());
        });

        run(function () use ($pool): void {
            $this->assertSame('U', (new DatedModel)->getDateFormat());
            $this->assertSame(0, $pool->getBorrowedCount());

            $connection = DB::connection('pool_test');
            $connection->setQueryGrammar(new SQLiteGrammar($connection));
        });

        run(function () use ($pool): void {
            $this->assertSame('Y-m-d H:i:s', (new DatedModel)->getDateFormat());
            $this->assertSame(0, $pool->getBorrowedCount());
        });
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testSharedReconnectRecordsTheNewFormatOnlyAfterListenersSucceed(bool $fail): void
    {
        DB::extend('pool_test', static fn (array $config): SQLiteConnection => new SQLiteConnection(
            new PDO('sqlite:' . $config['database']),
            $config['database'],
            $config['prefix'],
            $config,
        ));
        $pool = $this->app->make(PoolManager::class)->pool('pool_test');

        run(function () use ($pool, $fail): void {
            $connection = DB::connection('pool_test');
            $this->assertSame('Y-m-d H:i:s', $pool->recordedDateFormat());
            $failure = new RuntimeException('Listener refused the replacement session.');
            Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($pool, $fail, $failure): void {
                $this->assertNull($pool->recordedDateFormat());
                $event->connection->setQueryGrammar(new TimestampGrammar($event->connection));

                if ($fail) {
                    throw $failure;
                }
            });

            $caught = null;

            try {
                $connection->reconnect();
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $this->assertSame($fail ? $failure : null, $caught);
            $this->assertSame($fail ? null : 'U', $pool->recordedDateFormat());
        });

        $this->assertSame($fail ? null : 'U', $pool->recordedDateFormat());
    }

    public function testModelFormatsAndOverriddenConnectionsKeepTheirOwnDateFormats(): void
    {
        run(static fn () => DB::connection('pool_test')->select('select 1'));

        run(function (): void {
            $this->assertSame('d/m/Y', (new DatedModel)->setDateFormat('d/m/Y')->getDateFormat());
            $this->assertSame('U', (new OwnConnectionModel)->getDateFormat());
        });
    }

    /**
     * Configure a file-backed SQLite connection that borrows from its real pool during tests.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.pool_test', [
            'driver' => 'sqlite',
            'database' => self::$databaseDirectory . '/database.sqlite',
            'prefix' => '',
            'pool' => [
                'testing_enabled' => true,
                'min_retained_connections' => 1,
                'max_connections' => 1,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat_interval' => null,
                'max_idle_time' => 60.0,
            ],
        ]);
        $app->make('config')->set('database.connections.other', $app->make('config')->array('database.connections.pool_test'));
    }
}

class DatedModel extends Model
{
    protected UnitEnum|string|null $connection = 'pool_test';

    protected ?string $table = 'dated_models';

    protected array $casts = ['published_at' => 'datetime'];
}

class OwnConnectionModel extends Model
{
    protected ?string $table = 'dated_models';

    /**
     * Use a connection of the model's own making.
     */
    public function getConnection(): Connection
    {
        $connection = DB::connection('pool_test');
        $connection->setQueryGrammar(new TimestampGrammar($connection));

        return $connection;
    }
}

class TimestampGrammar extends SQLiteGrammar
{
    /**
     * Store dates as Unix timestamps.
     */
    public function getDateFormat(): string
    {
        return 'U';
    }
}
