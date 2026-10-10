<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\Tracing\EventHandler as TracingEventHandler;
use Mockery as m;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\Channel;

class CoroutineSafetyTest extends TestCase
{
    public function testIntegrationTransactionNameIsIsolatedPerCoroutine(): void
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

        $this->assertTrue($channel->pop(1.0));

        // Parent should still have its own transaction name
        $this->assertSame('/parent-route', Integration::getTransaction());

        // Child should not have inherited parent's transaction name
        $this->assertNull($childTransaction);
    }

    public function testTracingEventHandlerSpanStacksAreIsolatedPerCoroutine(): void
    {
        $handler = new TracingEventHandler(config()->array('sentry.tracing'));

        // We need a transaction on the hub for span operations to work
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->startTransaction(new TransactionContext('test'));
        $transaction->setSampled(true);
        $hub->setSpan($transaction);

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('parent');
        $handler->transactionBeginning(new TransactionBeginning($connection));

        // Verify parent has a span on its stack
        $parentStackKey = TracingEventHandler::CONTEXT_TRANSACTION_SPANS_KEY;
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
}
