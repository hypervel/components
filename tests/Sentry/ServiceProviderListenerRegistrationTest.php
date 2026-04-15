<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Events\Dispatcher;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Routing\Events\RouteMatched;

class ServiceProviderListenerRegistrationTest extends SentryTestCase
{
    public function testQueryExecutedIsNotRegisteredWhenSqlBreadcrumbsAndTracingAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
            'sentry.tracing.sql_queries' => false,
        ]);

        $this->assertFalse(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(0, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
        $this->assertTrue(app('events')->hasListeners(RouteMatched::class));
    }

    public function testQueryExecutedIsRegisteredForBreadcrumbsWithoutTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
            'sentry.tracing.sql_queries' => false,
        ]);

        $this->assertTrue(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(1, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
    }

    public function testQueryExecutedIsRegisteredForTracingWithoutBreadcrumbs(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
            'sentry.tracing.sql_queries' => true,
        ]);

        $this->assertTrue(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(1, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
    }

    public function testMessageLoggedIsNotRegisteredWhenLogBreadcrumbsAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => false,
        ]);

        $this->assertSame(0, $this->countMethodListeners(MessageLogged::class, 'messageLogged'));
    }

    public function testMessageLoggedIsRegisteredWhenLogBreadcrumbsAreEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.logs' => true,
        ]);

        $this->assertTrue(app('events')->hasListeners(MessageLogged::class));
        $this->assertSame(1, $this->countMethodListeners(MessageLogged::class, 'messageLogged'));
    }

    private function getEventDispatcher(): Dispatcher
    {
        /** @var Dispatcher $dispatcher */
        return app('events');
    }

    private function countMethodListeners(string $eventClass, string $method): int
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$eventClass] ?? [];

        return count(array_filter($listeners, static function (mixed $listener) use ($method): bool {
            return is_array($listener)
                && isset($listener[1])
                && $listener[1] === $method;
        }));
    }
}
