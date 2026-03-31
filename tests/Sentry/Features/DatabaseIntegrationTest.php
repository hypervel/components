<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\Breadcrumb;
use Sentry\Tracing\Span;

/**
 * @internal
 * @coversNothing
 */
class DatabaseIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    /**
     * Create a test database connection for breadcrumb event testing.
     */
    protected function createTestConnection(): Connection
    {
        return new Connection(fn () => null, '', '', ['name' => 'sqlite']);
    }

    // ──────────────────────────────────────────────────────
    // Span tests (upstream parity — test Tracing\EventHandler)
    // ──────────────────────────────────────────────────────

    public function testSpanIsCreatedForMySQLConnectionQuery(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => 'host-mysql',
                'port' => 3306,
                'username' => 'user-mysql',
                'password' => 'password',
                'database' => 'db-mysql',
            ],
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT "mysql"'
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals('db.sql.query', $span->getOp());
        $this->assertEquals('host-mysql', $span->getData()['server.address']);
        $this->assertEquals(3306, $span->getData()['server.port']);
    }

    public function testSpanIsCreatedForSqliteConnectionQuery(): void
    {
        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT "inmemory"'
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals('db.sql.query', $span->getOp());
        $this->assertNull($span->getData()['server.address']);
        $this->assertNull($span->getData()['server.port']);
    }

    public function testSqlBindingsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.sql_bindings' => true,
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT %',
            $bindings = ['1']
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals($bindings, $span->getData()['db.sql.bindings']);
    }

    public function testSqlBindingsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.sql_bindings' => false,
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT %',
            ['1']
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertFalse(isset($span->getData()['db.sql.bindings']));
    }

    public function testSqlOriginIsResolvedWhenEnabledAndOverTreshold(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.sql_origin' => true,
            'sentry.tracing.sql_origin_threshold_ms' => 10,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1', [], 20);

        $this->assertArrayHasKey('code.filepath', $span->getData());
    }

    public function testSqlOriginIsNotResolvedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.sql_origin' => false,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1');

        $this->assertArrayNotHasKey('code.filepath', $span->getData());
    }

    public function testSqlOriginIsNotResolvedWhenUnderThreshold(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => 1.0,
            'sentry.tracing.sql_origin' => true,
            'sentry.tracing.sql_origin_threshold_ms' => 10,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1', [], 5);

        $this->assertArrayNotHasKey('code.filepath', $span->getData());
    }

    // ──────────────────────────────────────────────────────
    // Breadcrumb tests (Hypervel — test EventHandler breadcrumbs)
    // ──────────────────────────────────────────────────────

    public function testQueryExecutedEventCreatesCorrectBreadcrumb(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
        ]);

        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [123],
            50.0,
            $this->createTestConnection()
        );

        $dispatcher->dispatch($event);

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);
        $this->assertEquals('db.sql.query', $breadcrumb->getCategory());
        $this->assertEquals('SELECT * FROM users WHERE id = ?', $breadcrumb->getMessage());
        $this->assertEquals(Breadcrumb::LEVEL_INFO, $breadcrumb->getLevel());
        $this->assertEquals([
            'connectionName' => 'sqlite',
            'executionTimeMs' => 50.0,
            'bindings' => [123],
        ], $breadcrumb->getMetadata());
    }

    public function testQueryExecutedEventWithoutBindingsWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
            'sentry.breadcrumbs.sql_bindings' => false,
        ]);

        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [123],
            50.0,
            $this->createTestConnection()
        );

        $dispatcher->dispatch($event);

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);
        $this->assertEquals([
            'connectionName' => 'sqlite',
            'executionTimeMs' => 50.0,
        ], $breadcrumb->getMetadata());
        $this->assertArrayNotHasKey('bindings', $breadcrumb->getMetadata());
    }

    public function testTransactionBeginningEventCreatesCorrectBreadcrumb(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new TransactionBeginning($this->createTestConnection());

        $dispatcher->dispatch($event);

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);
        $this->assertEquals('db.sql.transaction', $breadcrumb->getCategory());
        $this->assertEquals(TransactionBeginning::class, $breadcrumb->getMessage());
        $this->assertEquals([
            'connectionName' => 'sqlite',
        ], $breadcrumb->getMetadata());
    }

    public function testTransactionCommittedEventCreatesCorrectBreadcrumb(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new TransactionCommitted($this->createTestConnection());

        $dispatcher->dispatch($event);

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);
        $this->assertEquals('db.sql.transaction', $breadcrumb->getCategory());
        $this->assertEquals(TransactionCommitted::class, $breadcrumb->getMessage());
        $this->assertEquals([
            'connectionName' => 'sqlite',
        ], $breadcrumb->getMetadata());
    }

    public function testTransactionRolledBackEventCreatesCorrectBreadcrumb(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new TransactionRolledBack($this->createTestConnection());

        $dispatcher->dispatch($event);

        $breadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertInstanceOf(Breadcrumb::class, $breadcrumb);
        $this->assertEquals('db.sql.transaction', $breadcrumb->getCategory());
        $this->assertEquals(TransactionRolledBack::class, $breadcrumb->getMessage());
        $this->assertEquals([
            'connectionName' => 'sqlite',
        ], $breadcrumb->getMetadata());
    }

    public function testQueryExecutedEventIsIgnoredWhenFeatureDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new QueryExecuted(
            'SELECT * FROM users WHERE id = ?',
            [123],
            50.0,
            $this->createTestConnection()
        );

        $dispatcher->dispatch($event);

        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();
        $this->assertEmpty($breadcrumbs);
    }

    public function testTransactionEventIsIgnoredWhenFeatureDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_transactions' => false,
        ]);

        $dispatcher = $this->app->make(Dispatcher::class);

        $event = new TransactionBeginning($this->createTestConnection());

        $dispatcher->dispatch($event);

        $breadcrumbs = $this->getCurrentSentryBreadcrumbs();
        $this->assertEmpty($breadcrumbs);
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    private function executeQueryAndRetrieveSpan(string $query, array $bindings = [], int $time = 123): Span
    {
        $transaction = $this->startTransaction();

        $this->dispatchHypervelEvent(new QueryExecuted($query, $bindings, $time, DB::connection()));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1];
    }
}
