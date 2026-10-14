<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Tests\Sentry\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\Breadcrumb;

class DatabaseEventsTest extends TestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
        ]);

        $this->assertTrue($this->app->make('config')->boolean('sentry.breadcrumbs.sql_queries'));

        $this->dispatchHypervelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
    }

    public function testSqlBindingsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
        ]);

        $this->assertTrue($this->app->make('config')->boolean('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchHypervelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertEquals($bindings, $lastBreadcrumb->getMetadata()['bindings']);
        $this->assertSame('db.sql.query', $lastBreadcrumb->getCategory());
        $this->assertSame(Breadcrumb::LEVEL_INFO, $lastBreadcrumb->getLevel());
        $this->assertEquals([
            'connectionName' => 'test',
            'executionTimeMs' => 10,
            'bindings' => $bindings,
        ], $lastBreadcrumb->getMetadata());
    }

    public function testSqlQueriesAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $this->assertFalse($this->app->make('config')->boolean('sentry.breadcrumbs.sql_queries'));

        $this->dispatchHypervelEvent(new QueryExecuted(
            'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testSqlBindingsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => false,
        ]);

        $this->assertFalse($this->app->make('config')->boolean('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchHypervelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertFalse(isset($lastBreadcrumb->getMetadata()['bindings']));
        $this->assertEquals([
            'connectionName' => 'test',
            'executionTimeMs' => 10,
        ], $lastBreadcrumb->getMetadata());
    }

    public function testSqlQueryDataIsRecordedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => false,
            'sentry.data_collection' => [],
        ]);

        $this->dispatchHypervelEvent(new QueryExecuted(
            'SELECT * FROM users WHERE email = :email AND password = :password;',
            ['email' => 'foo@example.com', 'password' => 'secret'],
            10,
            $this->getMockedConnection()
        ));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('foo@example.com', $metadata['db.query.parameter.email']);
        $this->assertSame('[Filtered]', $metadata['db.query.parameter.password']);
        $this->assertArrayNotHasKey('bindings', $metadata);
    }

    public function testSqlQueryDataIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
            'sentry.data_collection' => [
                'database_query_data' => false,
            ],
        ]);

        $this->dispatchHypervelEvent(new QueryExecuted(
            'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertArrayNotHasKey('db.query.parameter.0', $metadata);
        $this->assertArrayNotHasKey('bindings', $metadata);
    }

    #[DataProvider('sqlTransactionEventProvider')]
    public function testSqlTransactionsAreRecordedWhenEnabled(string $eventClass): void
    {
        $this->dispatchHypervelEvent(new $eventClass($this->getMockedConnection()));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertSame('db.sql.transaction', $lastBreadcrumb->getCategory());
        $this->assertSame(Breadcrumb::LEVEL_INFO, $lastBreadcrumb->getLevel());
        $this->assertSame($eventClass, $lastBreadcrumb->getMessage());
        $this->assertSame(['connectionName' => 'test'], $lastBreadcrumb->getMetadata());
    }

    /**
     * Provide the database transaction events.
     */
    public static function sqlTransactionEventProvider(): iterable
    {
        yield 'beginning' => [TransactionBeginning::class];
        yield 'committed' => [TransactionCommitted::class];
        yield 'rolled back' => [TransactionRolledBack::class];
    }

    public function testSqlTransactionsAreNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_transactions' => false,
        ]);

        $this->dispatchHypervelEvent(new TransactionBeginning($this->getMockedConnection()));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    /**
     * Get a database connection mock.
     */
    private function getMockedConnection(): Connection
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('test');

        return $connection;
    }
}
