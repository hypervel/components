<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Log\Context\Repository;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\go;

class ContextCoroutineTest extends TestCase
{
    public function testContextIsIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);

        go(function () use ($channel) {
            Repository::getInstance()->add('source', 'coroutine-1');
            usleep(10_000); // Let the other coroutine run
            $channel->push(Repository::getInstance()->get('source'));
        });

        go(function () use ($channel) {
            Repository::getInstance()->add('source', 'coroutine-2');
            usleep(10_000);
            $channel->push(Repository::getInstance()->get('source'));
        });

        $results = [$channel->pop(), $channel->pop()];
        sort($results);

        $this->assertSame(['coroutine-1', 'coroutine-2'], $results);
    }

    public function testContextIsNotInheritedByChildCoroutines()
    {
        Repository::getInstance()->add('parent_key', 'parent_value');

        $channel = new Channel(1);

        go(function () use ($channel) {
            // Child coroutine should NOT see parent's context
            $channel->push(Repository::getInstance()->get('parent_key'));
        });

        $this->assertNull($channel->pop());
        // Parent still has its data
        $this->assertSame('parent_value', Repository::getInstance()->get('parent_key'));
    }

    public function testForkedCoroutineMutatingContextDoesNotAffectParent()
    {
        Repository::getInstance()->add('trace_id', 'parent-value');

        $channel = new Channel(1);

        // fork() copies all parent context into the child via CoroutineContext::copyFrom()
        Coroutine::fork(function () use ($channel) {
            // Child should see the copied value
            $channel->push(Repository::getInstance()->get('trace_id'));

            // Mutate in the child — this must NOT affect the parent
            Repository::getInstance()->add('trace_id', 'child-modified');
            Repository::getInstance()->add('child_only', 'child-data');
        });

        $childSaw = $channel->pop();
        $this->assertSame('parent-value', $childSaw);

        // Parent's context must be unchanged
        $this->assertSame('parent-value', Repository::getInstance()->get('trace_id'));
        $this->assertNull(Repository::getInstance()->get('child_only'));
    }

    public function testDehydrateAndHydrateAcrossCoroutines()
    {
        Repository::getInstance()->add('trace_id', 'abc-123');
        Repository::getInstance()->addHidden('secret', 'token');

        $payload = Repository::getInstance()->dehydrate();
        $this->assertNotNull($payload);

        $channel = new Channel(2);

        go(function () use ($channel, $payload) {
            Repository::getInstance()->hydrate($payload);
            $channel->push(Repository::getInstance()->get('trace_id'));
            $channel->push(Repository::getInstance()->getHidden('secret'));
        });

        $this->assertSame('abc-123', $channel->pop());
        $this->assertSame('token', $channel->pop());
    }
}
