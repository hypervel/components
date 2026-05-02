<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\co;
use function Hypervel\Coroutine\go;
use function Hypervel\Coroutine\parallel;

class FunctionTest extends TestCase
{
    public function testReturnOfGo()
    {
        $uniqid = uniqid();
        $id = go(function () use (&$uniqid) {
            $uniqid = 'Hypervel';
        });

        $this->assertTrue(is_int($id));
        $this->assertSame('Hypervel', $uniqid);
    }

    public function testDefer()
    {
        $channel = new Channel(10);
        parallel([function () use ($channel) {
            Coroutine::defer(function () use ($channel) {
                $channel->push(0);
            });
            Coroutine::defer(function () use ($channel) {
                $channel->push(1);
                Coroutine::defer(function () use ($channel) {
                    $channel->push(2);
                });
                Coroutine::defer(function () use ($channel) {
                    $channel->push(3);
                });
            });
            Coroutine::defer(function () use ($channel) {
                $channel->push(4);
            });
            $channel->push(5);
        }]);

        $this->assertSame(5, $channel->pop(0.001));
        $this->assertSame(4, $channel->pop(0.001));
        $this->assertSame(1, $channel->pop(0.001));
        $this->assertSame(3, $channel->pop(0.001));
        $this->assertSame(2, $channel->pop(0.001));
        $this->assertSame(0, $channel->pop(0.001));
    }

    public function testCoDoesNotCopyContextByDefault()
    {
        CoroutineContext::set('parent_only', 'value');

        $channel = new Channel(1);
        co(function () use ($channel) {
            $channel->push(CoroutineContext::get('parent_only'));
        });

        $this->assertNull($channel->pop());
    }

    public function testCoCopyContextTrueCopiesAllKeys()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        co(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        }, copyContext: true);

        $this->assertSame('value_a', $channel->pop());
        $this->assertSame('value_b', $channel->pop());
    }

    public function testCoCopyContextArrayCopiesSpecifiedKeysOnly()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        co(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        }, copyContext: ['key_a']);

        $this->assertSame('value_a', $channel->pop());
        $this->assertNull($channel->pop());
    }

    public function testGoDoesNotCopyContextByDefault()
    {
        CoroutineContext::set('parent_only', 'value');

        $channel = new Channel(1);
        go(function () use ($channel) {
            $channel->push(CoroutineContext::get('parent_only'));
        });

        $this->assertNull($channel->pop());
    }

    public function testGoCopyContextTrueCopiesAllKeys()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        go(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        }, copyContext: true);

        $this->assertSame('value_a', $channel->pop());
        $this->assertSame('value_b', $channel->pop());
    }

    public function testGoCopyContextArrayCopiesSpecifiedKeysOnly()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        go(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        }, copyContext: ['key_a']);

        $this->assertSame('value_a', $channel->pop());
        $this->assertNull($channel->pop());
    }
}
