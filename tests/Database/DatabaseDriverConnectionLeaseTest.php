<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Generator;
use Hypervel\Contracts\Database\LostConnectionDetector;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\LeasableConnection;
use Hypervel\Database\Pool\DatabasePool;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class DatabaseDriverConnectionLeaseTest extends TestCase
{
    protected int $generations = 0;

    /**
     * Configure a single physical slot for an opted-in extension driver.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.leased', [
            'driver' => 'leased',
            'database' => 'leased',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1, 'wait_timeout' => 0.2],
        ]);
    }

    /**
     * Register the opted-in driver, counting its physical generations.
     */
    protected function setUpInCoroutine(): void
    {
        DB::extend('leased', function (array $config): LeasedDriverConnection {
            ++$this->generations;

            return new LeasedDriverConnection($config['database'], $config['prefix'], $config);
        });
    }

    public function testIdleSessionsReturnTheirSlotWhileTheCallerKeepsItsState(): void
    {
        $connection = DB::connection('leased');
        $physical = $connection->physical();
        $connection->setTablePrefix('mine_');
        $connection->unsetEventDispatcher();

        $connection->withPinnedSession(function (): void {
            DB::releaseIdleConnections();
            $this->assertSame(0, $this->pool()->getIdleCount());
        });

        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());

        [[$other, $otherPhysical]] = parallel([static function (): array {
            $connection = DB::connection('leased');

            return [$connection, $connection->physical()];
        }]);

        $this->assertNotSame($connection, $other);
        $this->assertSame($physical, $otherPhysical);
        $this->assertSame('', $other->getTablePrefix());
        $this->assertSame($connection, DB::connection('leased'));
        $this->assertSame($physical, $connection->physical());
        $this->assertSame('mine_', $connection->getTablePrefix());
        $this->assertNull($connection->getEventDispatcher());
        $this->assertNull($physical->getEventDispatcher());
        $this->assertSame(1, $this->generations);
    }

    public function testRepeatedIdleReleaseDoesNotBorrowAnOccupiedSlot(): void
    {
        DB::connection('leased');
        DB::releaseIdleConnections();
        $borrowed = $this->pool()->borrow();

        try {
            DB::releaseIdleConnections();

            $this->assertSame(1, $this->pool()->getBorrowedCount());
        } finally {
            $borrowed->release();
        }
    }

    #[TestWith(['database'])]
    #[TestWith(['prefix'])]
    public function testDifferentEndpointIdentitiesRetainTheWholeConnection(string $setting): void
    {
        config(['database.connections.leased.write' => [[$setting => 'first'], [$setting => 'second']]]);
        $connection = DB::connection('leased');

        $this->assertSame($connection, $connection->physical());
        DB::releaseIdleConnections();

        $this->assertSame(0, $this->pool()->getIdleCount());
        $this->assertSame($connection, DB::connection('leased'));
    }

    public function testModifiedConnectionsKeepTheirSessionWhenWritersDiffer(): void
    {
        config(['database.connections.leased.host' => ['first', 'second']]);
        $connection = DB::connection('leased');
        $physical = $connection->physical();
        $connection->recordsHaveBeenModified();

        DB::releaseIdleConnections();

        $this->assertSame(0, $this->pool()->getIdleCount());
        $this->assertSame($physical, $connection->physical());

        $connection->forgetRecordModificationState();
        DB::releaseIdleConnections();

        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    public function testNamedExtensionsCanLeaseWithoutADriverSetting(): void
    {
        $configuration = config('database.connections.leased');
        unset($configuration['driver']);
        $configuration['write'] = [['host' => 'first'], ['host' => 'second']];
        config(['database.connections.leased' => $configuration]);
        $connection = DB::connection('leased');
        $physical = $connection->physical();

        $this->assertNotSame($connection, $physical);
        $connection->recordsHaveBeenModified();
        DB::releaseIdleConnections();

        $this->assertSame(0, $this->pool()->getIdleCount());
        $this->assertSame($physical, $connection->physical());

        $connection->forgetRecordModificationState();
        DB::releaseIdleConnections();

        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    public function testDisconnectClosesOnlyAHeldPhysicalConnectionAndEachGenerationIsAnnouncedOnce(): void
    {
        $established = [];
        Event::listen(ConnectionEstablished::class, static function (ConnectionEstablished $event) use (&$established): void {
            $established[] = $event->connection;
        });
        $connection = DB::connection('leased');
        $physical = $connection->physical();
        DB::releaseIdleConnections();
        $connection->physical();

        DB::disconnect('leased');

        $this->assertFalse($physical->open);
        $replacement = $connection->physical();
        $this->assertNotSame($physical, $replacement);
        $this->assertSame([$connection, $connection], $established);
        $this->assertSame(1, $this->pool()->getManagedCount());

        DB::disconnect('leased');

        $this->assertFalse($replacement->open);
        $current = $connection->physical();
        DB::releaseIdleConnections();
        DB::disconnect('leased');

        $this->assertTrue($current->open);
        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->assertSame($current, $connection->physical());
        $this->assertCount(3, $established);
        $this->assertSame(3, $this->generations);
    }

    public function testQueriesRunWhileReconnectingDoNotReconnectAgain(): void
    {
        $failure = new RuntimeException('The remote database connection was lost.');
        $detector = m::mock(LostConnectionDetector::class);
        $detector->shouldReceive('causedByLostConnection')->with($failure)->andReturnTrue();
        $this->app->instance(LostConnectionDetector::class, $detector);
        $connection = DB::connection('leased');
        $reconnects = 0;

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($failure, &$reconnects): void {
            if (++$reconnects > 1) {
                $this->fail('The leased connection reconnected while it was already reconnecting.');
            }

            $event->connection->runFailingQuery($failure);
        });

        try {
            $connection->runFailingQuery($failure);
            $this->fail('A QueryException was not thrown.');
        } catch (QueryException $exception) {
            $this->assertSame(1, $reconnects);
            $this->assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAnEndedOwnerResetsItsWorkAndCannotBorrowAgain(): void
    {
        [$retained] = parallel([static function (): LeasedDriverConnection {
            $connection = DB::connection('leased');
            $connection->physical();

            return $connection;
        }]);

        $this->assertSame(1, $retained->resets);
        $this->assertSame(1, $this->pool()->getIdleCount());
        $this->expectException(LogicException::class);

        $retained->physical();
    }

    public function testReacquisitionListenerFailureDiscardsTheSlotAndKeepsTheOwnerUsable(): void
    {
        $connection = DB::connection('leased');
        DB::disconnect('leased');
        $failure = new RuntimeException('listener failed');
        $fail = true;
        Event::listen(ConnectionEstablished::class, static function () use ($failure, &$fail): void {
            if ($fail) {
                $fail = false;

                throw $failure;
            }
        });

        try {
            $connection->physical();
            $this->fail('Expected the connection listener to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $this->pool()->getManagedCount());
        $this->assertTrue($connection->physical()->open);
        DB::releaseIdleConnections();
        $this->assertSame(1, $this->pool()->getIdleCount());
    }

    /**
     * Get the opted-in driver's pool.
     */
    protected function pool(): DatabasePool
    {
        return $this->app->make(PoolManager::class)->pool('leased');
    }
}

class LeasedDriverConnection extends Connection implements LeasableConnection
{
    public bool $open = true;

    public int $resets = 0;

    /** @var null|Closure(): self */
    protected ?Closure $physicalResolver = null;

    /**
     * Create a logical connection that holds no physical resources.
     */
    public function newLeasedConnection(Closure $physical): static
    {
        $connection = new static($this->database, $this->tablePrefix, $this->config);
        $connection->open = false;
        $connection->physicalResolver = $physical;

        return $connection;
    }

    /**
     * Get the physical connection serving the next operation.
     */
    public function physical(): self
    {
        return $this->physicalResolver === null ? $this : ($this->physicalResolver)();
    }

    public function select(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): array
    {
        return [];
    }

    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from [];
    }

    public function statement(string $query, array $bindings = []): bool
    {
        return true;
    }

    /**
     * Run a failing operation through the driver's normal retry boundary.
     */
    public function runFailingQuery(Throwable $failure): void
    {
        $this->run('query', [], function () use ($failure): never {
            $this->physical();

            throw $failure;
        });
    }

    public function affectingStatement(string $query, array $bindings = []): int
    {
        return 0;
    }

    public function unprepared(string $query): bool
    {
        return true;
    }

    public function resetForPool(): void
    {
        ++$this->resets;

        parent::resetForPool();
    }

    public function ping(): bool
    {
        return $this->open;
    }

    public function inTransaction(): bool
    {
        return $this->physicalResolver !== null && $this->physical()->inTransaction();
    }

    public function getServerVersion(): string
    {
        return 'test';
    }

    protected function getDefaultDriverName(): string
    {
        return 'leased';
    }

    protected function escapeString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function hasDriverResources(): bool
    {
        return $this->open || $this->physicalResolver !== null;
    }

    protected function disconnectDriverResources(): void
    {
        $this->forgetDriverResources();
    }

    protected function forgetDriverResources(): void
    {
        $this->open = false;
    }

    protected function replaceDriverResources(Connection $fresh): void
    {
        /** @var self $fresh */
        $open = $fresh->open;

        try {
            $this->disconnectDriverResources();
        } finally {
            $this->open = $open;
        }
    }
}
