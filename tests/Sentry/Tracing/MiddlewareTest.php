<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Tracing;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Request;
use Hypervel\Sentry\Tracing\Middleware;
use Hypervel\Tests\Sentry\TestCase;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Response;

class MiddlewareTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testScopedRegistrationGivesDifferentInstancesPerCoroutine(): void
    {
        // Verify the middleware is registered as scoped
        $this->assertTrue($this->app->isScoped(Middleware::class));

        $instance1 = $this->app->make(Middleware::class);
        $instance2 = null;

        $channel = new Channel(1);

        Coroutine::create(function () use (&$instance2, $channel): void {
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

    public function testSameCoroutineGetsSameInstance(): void
    {
        $instance1 = $this->app->make(Middleware::class);
        $instance2 = $this->app->make(Middleware::class);

        $this->assertSame(
            $instance1,
            $instance2,
            'Same coroutine should get the same scoped middleware instance'
        );
    }

    public function testTransactionUsesTheRequestStartTimestamp(): void
    {
        $middleware = $this->app->make(Middleware::class);
        $request = new Request(server: [
            'REQUEST_TIME_FLOAT' => 1_700_000_000.123456,
        ]);

        $request->server->set('REQUEST_TIME_FLOAT', 1_800_000_000.654321);

        $response = $middleware->handle($request, static fn (): Response => new Response('OK'));

        Middleware::signalRouteWasMatched();
        $middleware->terminate($request, $response);
        $middleware->finishTransaction();

        $this->assertSentryTransactionCount(1);
        $this->assertSame(1_700_000_000.123456, $this->getLastSentryEvent()?->getStartTimestamp());
    }

    public function testRequestSpansStartWithinTheRequest(): void
    {
        $this->app->make('router')->get('/test', static fn (): string => 'OK');

        $this->get('/test')->assertOk();

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);
        $this->assertContains('middleware.handle', array_map(
            static fn (Span $span): ?string => $span->getOp(),
            $transaction->getSpans(),
        ));

        foreach ($transaction->getSpans() as $span) {
            $this->assertGreaterThanOrEqual($transaction->getStartTimestamp(), $span->getStartTimestamp());
            $this->assertGreaterThanOrEqual($span->getStartTimestamp(), $span->getEndTimestamp());
        }
    }

    public function testAfterResponseSpanAppearsOnCapturedTransaction(): void
    {
        $middleware = $this->app->make(Middleware::class);
        $request = Request::create('/test', 'GET');

        // Simulate handle() — starts the transaction
        $response = $middleware->handle($request, static fn (): Response => new Response('OK'));

        // Signal that a route was matched (otherwise terminate discards the transaction)
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

    public function testTerminateFinishesTransactionWhenAfterResponseTracingIsDisabled(): void
    {
        $middleware = new Middleware(false, true);
        $request = Request::create('/test', 'GET');

        $response = $middleware->handle($request, static fn (): Response => new Response('OK'));
        $middleware->terminate($request, $response);

        $this->assertSentryTransactionCount(1);

        $middleware->finishTransaction();

        $this->assertSentryTransactionCount(1);
    }
}
