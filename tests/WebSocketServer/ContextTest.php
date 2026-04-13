<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Context\CoroutineContext;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Context;

use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class ContextTest extends TestCase
{
    public function testHas()
    {
        Context::set('a', 42);
        $this->assertTrue(Context::has('a'));
    }

    public function testGet()
    {
        Context::set('a', 42);
        $this->assertEquals(42, Context::get('a'));
    }

    public function testForget()
    {
        Context::set('a', 42);
        Context::forget('a');
        $this->assertFalse(Context::has('a'));
    }

    public function testRelease()
    {
        Context::set('a', 42);
        Context::release();
        $this->assertFalse(Context::has('a'));
    }

    public function testCopy()
    {
        CoroutineContext::set(Context::FD, 2);
        Context::set('a', 42);
        parallel([function () {
            CoroutineContext::set(Context::FD, 3);
            Context::copyFrom(2);
            $this->assertEquals(42, Context::get('a'));
        }, function () {
            CoroutineContext::set(Context::FD, 3);
            Context::copyFrom(2, ['a']);
            $this->assertEquals(42, Context::get('a'));
        }]);
        $this->assertEquals(42, Context::get('a', 0, 3));
    }

    public function testCopyFromPreservesExistingValues()
    {
        CoroutineContext::set(Context::FD, 2);
        Context::set('a', 42);
        parallel([function () {
            CoroutineContext::set(Context::FD, 3);
            Context::set('b', 99);
            Context::copyFrom(2);
            // Copied value is present.
            $this->assertEquals(42, Context::get('a'));
            // Context::copyFrom() merges — existing values are preserved.
            $this->assertEquals(99, Context::get('b'));
        }, function () {
            CoroutineContext::set(Context::FD, 3);
            Context::set('b', 99);
            Context::copyFrom(2, ['a']);
            // Copied value is present.
            $this->assertEquals(42, Context::get('a'));
            // Context::copyFrom() merges — existing values are preserved.
            $this->assertEquals(99, Context::get('b'));
        }]);
    }

    public function testOverride()
    {
        Context::set('override.id', 1);
        $this->assertSame(2, Context::override('override.id', function ($id) {
            return $id + 1;
        }));

        $this->assertSame(2, Context::get('override.id'));
    }

    public function testGetOrSet()
    {
        Context::set('test.store.id', null);
        $this->assertSame(1, Context::getOrSet('test.store.id', function () {
            return 1;
        }));
        $this->assertSame(1, Context::getOrSet('test.store.id', function () {
            return 2;
        }));
        Context::set('test.store.id', null);
        $this->assertSame(1, Context::getOrSet('test.store.id', 1));
    }
}
