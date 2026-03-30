<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Tracing\Middleware;
use ReflectionClass;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class TracingMiddlewareTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testScopedRegistrationGivesDifferentInstancesPerCoroutine()
    {
        // Verify the middleware is registered as scoped
        $this->assertTrue($this->app->isScoped(Middleware::class));

        $instance1 = $this->app->make(Middleware::class);
        $instance2 = null;

        $channel = new \Swoole\Coroutine\Channel(1);

        \Hypervel\Coroutine\Coroutine::create(function () use (&$instance2, $channel) {
            $instance2 = $this->app->make(Middleware::class);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertNotNull($instance2);
        $this->assertNotSame(
            $instance1,
            $instance2,
            'Scoped middleware should give different instances to different coroutines'
        );
    }

    public function testSameCoroutineGetsSameInstance()
    {
        $instance1 = $this->app->make(Middleware::class);
        $instance2 = $this->app->make(Middleware::class);

        $this->assertSame(
            $instance1,
            $instance2,
            'Same coroutine should get the same scoped middleware instance'
        );
    }

    public function testBootedTimestampIsStaticAndSharedAcrossInstances()
    {
        // Reset the static timestamp
        Middleware::setBootedTimestamp(1234567890.123);

        // Even though scoped gives different instances per coroutine,
        // the static bootedTimestamp should be visible from any instance
        $reflection = new ReflectionClass(Middleware::class);
        $property = $reflection->getProperty('bootedTimestamp');

        $this->assertSame(1234567890.123, $property->getValue());

        // Clean up
        Middleware::setBootedTimestamp(null);
        $property->setValue(null, null);
    }

    public function testAfterResponseSpansAreCapturedOnTransaction()
    {
        $middleware = $this->app->make(Middleware::class);
        $request = Request::create('/test', 'GET');

        // Simulate handle() — starts the transaction
        $response = $middleware->handle($request, fn () => new Response('OK'));

        // Signal that a route was matched (otherwise terminate discards the transaction)
        Middleware::signalRouteWasMatched();

        // Simulate terminate() — finishes appSpan, hydrates response
        $middleware->terminate($request, $response);

        // At this point, the transaction is finished in the current code.
        // Any span created after terminate() would be lost.
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();

        // With the current (broken) code, the transaction is already finished
        // and parentSpan is null — after-response work can't attach spans.
        // With the fix, the transaction stays open until coroutine exit.
        $this->assertNotNull(
            $parentSpan,
            'Transaction should still be open after terminate() to capture after-response work'
        );
    }

    public function testAfterResponseSpanAppearsOnCapturedTransaction()
    {
        $middleware = $this->app->make(Middleware::class);
        $request = Request::create('/test', 'GET');

        // Simulate handle() — starts the transaction
        $response = $middleware->handle($request, fn () => new Response('OK'));

        // Signal that a route was matched
        Middleware::signalRouteWasMatched();

        // Simulate terminate() — finishes appSpan, hydrates response, transaction stays open
        $middleware->terminate($request, $response);

        // Simulate after-response work (e.g. dispatchAfterResponse)
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $this->assertNotNull($parentSpan);

        $afterResponseSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('queue.afterResponse')
                ->setDescription('after-response work')
        );
        $afterResponseSpan->finish();

        // Simulate what the defer does — finish the transaction
        $middleware->finishTransaction();

        // The transaction should have been captured
        $this->assertSentryTransactionCount(1);

        $transaction = $this->getLastSentryEvent();
        $this->assertNotNull($transaction);

        // The after-response span should be present
        $found = false;
        foreach ($transaction->getSpans() as $span) {
            if ($span->getOp() === 'queue.afterResponse') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'After-response span should be captured on the transaction');
    }
}
