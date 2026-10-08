<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Tests\Sentry\TestCase;
use Mockery as m;

class DatabaseEventsTest extends TestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.sql_queries' => true]),
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
            'sentry' => $this->sentryConfigWith(['breadcrumbs.sql_bindings' => true]),
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
    }

    public function testSqlQueriesAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith(['breadcrumbs.sql_queries' => false]),
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
            'sentry' => $this->sentryConfigWith(['breadcrumbs.sql_bindings' => false]),
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
    }

    public function testSqlQueryDataIsRecordedWithDataCollection(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.sql_bindings' => false,
                'data_collection' => [],
            ]),
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
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.sql_bindings' => true,
                'data_collection' => [
                    'database_query_data' => false,
                ],
            ]),
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
