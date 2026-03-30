<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Sentry\Features\CacheFeature;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\Tracing\EventHandler as TracingEventHandler;
use Hypervel\Tests\TestCase;
use ReflectionMethod;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\Channel;

/**
 * Verify that per-request mutable state is isolated between concurrent coroutines.
 *
 * Verifies that instance properties and static properties used for per-request
 * mutable state are properly isolated between concurrent coroutines.
 *
 * @internal
 * @coversNothing
 */
class CoroutineSafetyTest extends TestCase
{
    public function testIntegrationTransactionNameIsIsolatedPerCoroutine()
    {
        // Set transaction name in parent coroutine
        Integration::setTransaction('/parent-route');

        $childTransaction = null;
        $channel = new Channel(1);

        Coroutine::create(function () use (&$childTransaction, $channel) {
            // Child coroutine should NOT see parent's transaction name
            $childTransaction = Integration::getTransaction();

            // Set a different name in the child
            Integration::setTransaction('/child-route');

            $channel->push(true);
        });

        $channel->pop(1.0);

        // Parent should still have its own transaction name
        $this->assertSame('/parent-route', Integration::getTransaction());

        // Child should not have inherited parent's transaction name
        $this->assertNull($childTransaction);
    }

    public function testTracingEventHandlerSpanStacksAreIsolatedPerCoroutine()
    {
        $handler = new TracingEventHandler([]);

        // We need a transaction on the hub for span operations to work
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->startTransaction(new TransactionContext('test'));
        $transaction->setSampled(true);
        $hub->setSpan($transaction);

        // Push a span in the parent coroutine via a mock DB transaction event
        $parentSpan = $transaction->startChild(SpanContext::make()->setOp('test.parent'));
        $this->pushSpanOnHandler($handler, $parentSpan);

        // Verify parent has a span on its stack
        $parentStackKey = TracingEventHandler::CONTEXT_CURRENT_SPANS_KEY;
        $parentStack = CoroutineContext::get($parentStackKey, []);
        $this->assertCount(1, $parentStack);

        $childStackCount = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($parentStackKey, &$childStackCount, $channel) {
            // Child coroutine should have an empty span stack
            $childStackCount = count(CoroutineContext::get($parentStackKey, []));
            $channel->push(true);
        });

        $channel->pop(1.0);

        // Child should not see parent's span stack
        $this->assertSame(0, $childStackCount);

        // Parent's stack should be unaffected
        $this->assertCount(1, CoroutineContext::get($parentStackKey, []));
    }

    public function testTracksPushedScopesAndSpansTraitIsIsolatedPerCoroutine()
    {
        // The trait uses Context keys namespaced by class name.
        // Verify that different coroutines get independent stacks.
        $featureClass = 'Hypervel\Sentry\Features\CacheFeature';
        $scopeKey = CacheFeature::SPANS_CONTEXT_PREFIX . $featureClass . '.scope_count';
        $currentSpansKey = CacheFeature::SPANS_CONTEXT_PREFIX . $featureClass . '.current_spans';

        // Simulate pushing scope/span state in parent
        CoroutineContext::set($scopeKey, 3);
        CoroutineContext::set($currentSpansKey, ['span1', 'span2', 'span3']);

        $childScopeCount = null;
        $childSpanCount = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($scopeKey, $currentSpansKey, &$childScopeCount, &$childSpanCount, $channel) {
            $childScopeCount = CoroutineContext::get($scopeKey, 0);
            $childSpanCount = count(CoroutineContext::get($currentSpansKey, []));
            $channel->push(true);
        });

        $channel->pop(1.0);

        // Child should have fresh (empty) state
        $this->assertSame(0, $childScopeCount);
        $this->assertSame(0, $childSpanCount);

        // Parent should be unaffected
        $this->assertSame(3, CoroutineContext::get($scopeKey, 0));
        $this->assertCount(3, CoroutineContext::get($currentSpansKey, []));
    }

    /**
     * Use reflection to call the private pushSpan method on TracingEventHandler.
     */
    private function pushSpanOnHandler(TracingEventHandler $handler, Span $span): void
    {
        $method = new ReflectionMethod($handler, 'pushSpan');
        $method->invoke($handler, $span);
    }
}
