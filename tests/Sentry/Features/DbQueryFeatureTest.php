<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Tests\Sentry\SentryTestCase;
use ReflectionException;
use Sentry\Breadcrumb;

/**
 * @internal
 * @coversNothing
 */
class DbQueryFeatureTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.breadcrumbs.sql_queries' => true,
        'sentry.breadcrumbs.sql_bindings' => true,
        'sentry.breadcrumbs.sql_transactions' => true,
    ];

    /**
     * Create a test database connection for event testing.
     */
    protected function createTestConnection(): Connection
    {
        return new Connection(fn () => null, '', '', ['name' => 'sqlite']);
    }

    /**
     * @throws ReflectionException
     */
    public function testQueryExecutedEventCreatesCorrectBreadcrumb(): void
    {
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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
}
