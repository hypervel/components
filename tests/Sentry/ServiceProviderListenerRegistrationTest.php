<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Events\Dispatcher;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use Hypervel\WebSocketServer\Events\ConnectionClosing;
use Hypervel\WebSocketServer\Events\ConnectionOpening;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Mockery as m;
use ReflectionFunction;

class ServiceProviderListenerRegistrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testQueryExecutedIsNotRegisteredWhenSqlBreadcrumbsAndTracingAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.sql_queries' => false,
                'tracing.sql_queries' => false,
            ]),
        ]);

        $this->assertFalse(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(0, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
        $this->assertTrue(app('events')->hasListeners(RouteMatched::class));
    }

    public function testQueryExecutedIsRegisteredForBreadcrumbsWithoutTracing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.sql_queries' => true,
                'tracing.sql_queries' => false,
            ]),
        ]);

        $this->assertTrue(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(1, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
    }

    public function testQueryExecutedIsRegisteredForTracingWithoutBreadcrumbs(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.sql_queries' => false,
                'tracing.sql_queries' => true,
            ]),
        ]);

        $this->assertTrue(app('events')->hasListeners(QueryExecuted::class));
        $this->assertSame(1, $this->countMethodListeners(QueryExecuted::class, 'queryExecuted'));
    }

    public function testMessageLoggedIsNotRegisteredWhenLogBreadcrumbsAreDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.logs' => false,
            ]),
        ]);

        $this->assertSame(0, $this->countMethodListeners(MessageLogged::class, 'messageLogged'));
    }

    public function testMessageLoggedIsRegisteredWhenLogBreadcrumbsAreEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry' => $this->sentryConfigWith([
                'breadcrumbs.logs' => true,
            ]),
        ]);

        $this->assertTrue(app('events')->hasListeners(MessageLogged::class));
        $this->assertSame(1, $this->countMethodListeners(MessageLogged::class, 'messageLogged'));
    }

    public function testRuntimeContextBoundariesPrecedeFeatureListenersAndResolveAtDispatchTime(): void
    {
        $boundary = m::mock(RuntimeContextBoundary::class);
        $boundary->shouldReceive('start')->times(5);
        $this->app->instance(RuntimeContextBoundary::class, $boundary);

        foreach ([
            JobProcessing::class,
            ScheduledTaskStarting::class,
            ConnectionOpening::class,
            MessageReceived::class,
            ConnectionClosing::class,
        ] as $event) {
            $listeners = $this->getEventDispatcher()->getRawListeners()[$event] ?? [];
            $boundaryListenerIndex = $this->findBoundaryListenerIndex($event);

            $this->assertNotEmpty($listeners);
            $this->assertIsInt($boundaryListenerIndex, "Missing Sentry boundary listener for [{$event}].");

            $listeners[$boundaryListenerIndex]();
        }

        $this->assertLessThan(
            $this->findMethodListenerIndex(JobProcessing::class, 'handleJobProcessingQueueEvent'),
            $this->findBoundaryListenerIndex(JobProcessing::class),
        );
        $this->assertLessThan(
            $this->findMethodListenerIndex(ScheduledTaskStarting::class, 'handleScheduledTaskStarting'),
            $this->findBoundaryListenerIndex(ScheduledTaskStarting::class),
        );
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

    private function findMethodListenerIndex(string $eventClass, string $method): ?int
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$eventClass] ?? [];

        foreach ($listeners as $index => $listener) {
            if (is_array($listener)
                && isset($listener[1])
                && $listener[1] === $method) {
                return $index;
            }
        }

        return null;
    }

    private function findBoundaryListenerIndex(string $eventClass): ?int
    {
        $listeners = $this->getEventDispatcher()->getRawListeners()[$eventClass] ?? [];

        foreach ($listeners as $index => $listener) {
            if ($listener instanceof Closure
                && (new ReflectionFunction($listener))->getClosureThis() instanceof SentryServiceProvider) {
                return $index;
            }
        }

        return null;
    }
}
