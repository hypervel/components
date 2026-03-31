<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\EventHandler;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEventsTest extends SentryTestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

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

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

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
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

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

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

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

    private function getMockedConnection(): Connection
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('test');

        return $connection;
    }
}
