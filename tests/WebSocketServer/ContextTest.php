<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Context\Context as CoContext;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Context;

use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class ContextTest extends TestCase
{
    use RunTestsInCoroutine;
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

    public function testDestroy()
    {
        Context::set('a', 42);
        Context::destroy('a');
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
        CoContext::set(Context::FD, 2);
        Context::set('a', 42);
        parallel([function () {
            CoContext::set(Context::FD, 3);
            Context::copy(2);
            $this->assertEquals(42, Context::get('a'));
        }, function () {
            CoContext::set(Context::FD, 3);
            Context::copy(2, ['a']);
            $this->assertEquals(42, Context::get('a'));
        }]);
        $this->assertEquals(42, Context::get('a', 0, 3));
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
