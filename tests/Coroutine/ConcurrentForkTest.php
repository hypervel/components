<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class ConcurrentForkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Container::setInstance(new Container);
    }

    public function testForkCopiesSpecifiedContextKeys()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        $concurrent = new Concurrent(10);

        $concurrent->fork(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        }, ['key_a']);

        $this->assertSame('value_a', $channel->pop());
        $this->assertNull($channel->pop());
    }

    public function testForkDoesNotCopyUnspecifiedKeys()
    {
        CoroutineContext::set('included', 'yes');
        CoroutineContext::set('excluded', 'no');

        $channel = new Channel(1);
        $concurrent = new Concurrent(10);

        $concurrent->fork(function () use ($channel) {
            $channel->push(CoroutineContext::get('excluded'));
        }, ['included']);

        $this->assertNull($channel->pop());
    }

    public function testForkWithEmptyKeysCopiesAllContext()
    {
        CoroutineContext::set('key_x', 'x');
        CoroutineContext::set('key_y', 'y');

        $channel = new Channel(2);
        $concurrent = new Concurrent(10);

        $concurrent->fork(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_x'));
            $channel->push(CoroutineContext::get('key_y'));
        });

        $this->assertSame('x', $channel->pop());
        $this->assertSame('y', $channel->pop());
    }

    public function testForkRespectsConcurrencyLimit()
    {
        $concurrent = new Concurrent($limit = 5);
        $count = 0;

        for ($i = 0; $i < 10; ++$i) {
            $concurrent->fork(function () use (&$count) {
                Coroutine::sleep(0.05);
                ++$count;
            });
        }

        // With limit=5 and 10 tasks, 5 should be running (blocking push) and 5 already completed
        $this->assertTrue($concurrent->isFull());
        $this->assertSame(5, $count);
        $this->assertSame($limit, $concurrent->getRunningCoroutineCount());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }

        $this->assertSame(10, $count);
    }

    public function testForkReleasesChannelSlotOnCompletion()
    {
        $concurrent = new Concurrent(2);

        $concurrent->fork(function () {
            // No-op
        });

        // Wait for completion
        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.001);
        }

        $this->assertTrue($concurrent->isEmpty());
        $this->assertSame(0, $concurrent->getRunningCoroutineCount());
    }

    public function testForkReleasesChannelSlotOnException()
    {
        $concurrent = new Concurrent(2);

        $concurrent->fork(function () {
            throw new Exception('test error');
        });

        // Wait for completion
        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.001);
        }

        $this->assertTrue($concurrent->isEmpty());
        $this->assertSame(0, $concurrent->getRunningCoroutineCount());
    }

    public function testForkChildMutationsDoNotAffectParent()
    {
        CoroutineContext::set('shared', 'parent');

        $channel = new Channel(1);
        $concurrent = new Concurrent(10);

        $concurrent->fork(function () use ($channel) {
            CoroutineContext::set('shared', 'child-modified');
            CoroutineContext::set('child_only', 'exists');
            $channel->push(true);
        });

        $channel->pop();

        $this->assertSame('parent', CoroutineContext::get('shared'));
        $this->assertNull(CoroutineContext::get('child_only'));
    }
}
