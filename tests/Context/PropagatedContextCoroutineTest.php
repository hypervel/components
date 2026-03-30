<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class PropagatedContextCoroutineTest extends TestCase
{
    public function testPropagatedContextIsIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);

        go(function () use ($channel) {
            CoroutineContext::propagated()->add('source', 'coroutine-1');
            usleep(10_000); // Let the other coroutine run
            $channel->push(CoroutineContext::propagated()->get('source'));
        });

        go(function () use ($channel) {
            CoroutineContext::propagated()->add('source', 'coroutine-2');
            usleep(10_000);
            $channel->push(CoroutineContext::propagated()->get('source'));
        });

        $results = [$channel->pop(), $channel->pop()];
        sort($results);

        $this->assertSame(['coroutine-1', 'coroutine-2'], $results);
    }

    public function testPropagatedContextIsNotInheritedByChildCoroutines()
    {
        CoroutineContext::propagated()->add('parent_key', 'parent_value');

        $channel = new Channel(1);

        go(function () use ($channel) {
            // Child coroutine should NOT see parent's propagated context
            $channel->push(CoroutineContext::propagated()->get('parent_key'));
        });

        $this->assertNull($channel->pop());
        // Parent still has its data
        $this->assertSame('parent_value', CoroutineContext::propagated()->get('parent_key'));
    }

    public function testForkedCoroutineMutatingPropagatedContextDoesNotAffectParent()
    {
        CoroutineContext::propagated()->add('trace_id', 'parent-value');

        $channel = new Channel(1);

        // fork() copies all parent context into the child via CoroutineContext::copyFrom()
        Coroutine::fork(function () use ($channel) {
            // Child should see the copied value
            $channel->push(CoroutineContext::propagated()->get('trace_id'));

            // Mutate in the child — this must NOT affect the parent
            CoroutineContext::propagated()->add('trace_id', 'child-modified');
            CoroutineContext::propagated()->add('child_only', 'child-data');
        });

        $childSaw = $channel->pop();
        $this->assertSame('parent-value', $childSaw);

        // Parent's propagated context must be unchanged
        $this->assertSame('parent-value', CoroutineContext::propagated()->get('trace_id'));
        $this->assertNull(CoroutineContext::propagated()->get('child_only'));
    }

    public function testPropagatedDehydrateAndHydrateAcrossCoroutines()
    {
        CoroutineContext::propagated()->add('trace_id', 'abc-123');
        CoroutineContext::propagated()->addHidden('secret', 'token');

        $payload = CoroutineContext::propagated()->dehydrate();
        $this->assertNotNull($payload);

        $channel = new Channel(2);

        go(function () use ($channel, $payload) {
            CoroutineContext::propagated()->hydrate($payload);
            $channel->push(CoroutineContext::propagated()->get('trace_id'));
            $channel->push(CoroutineContext::propagated()->getHidden('secret'));
        });

        $this->assertSame('abc-123', $channel->pop());
        $this->assertSame('token', $channel->pop());
    }
}
