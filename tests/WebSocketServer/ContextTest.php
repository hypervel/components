<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Context\CoroutineContext;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Context;

use function Hypervel\Coroutine\parallel;

class ContextTest extends TestCase
{
    public function testHas(): void
    {
        Context::set('a', 42);
        $this->assertTrue(Context::has('a'));
    }

    public function testHasRecognizesStoredNull(): void
    {
        Context::set('a', null);

        $this->assertTrue(Context::has('a'));
    }

    public function testGet(): void
    {
        Context::set('a', 42);
        $this->assertSame(42, Context::get('a'));
    }

    public function testForget(): void
    {
        Context::set('a', 42);
        Context::forget('a');
        $this->assertFalse(Context::has('a'));
    }

    public function testForgetRemovesDottedKeyWithoutRemovingSiblings(): void
    {
        Context::set('profile.name', 'Taylor');
        Context::set('profile.email', 'taylor@example.com');

        Context::forget('profile.name');

        $this->assertFalse(Context::has('profile.name'));
        $this->assertSame('taylor@example.com', Context::get('profile.email'));
    }

    public function testRelease(): void
    {
        Context::set('a', 42);
        Context::release();
        $this->assertFalse(Context::has('a'));
    }

    public function testCopy(): void
    {
        CoroutineContext::set(Context::FD, 2);
        Context::set('a', 42);
        parallel([function () {
            CoroutineContext::set(Context::FD, 3);
            Context::copyFrom(2);
            $this->assertSame(42, Context::get('a'));
        }, function () {
            CoroutineContext::set(Context::FD, 3);
            Context::copyFrom(2, ['a']);
            $this->assertSame(42, Context::get('a'));
        }]);
        $this->assertSame(42, Context::get('a', 0, 3));
    }

    public function testCopyFromPreservesExistingValues(): void
    {
        CoroutineContext::set(Context::FD, 2);
        Context::set('a', 42);
        parallel([function () {
            CoroutineContext::set(Context::FD, 3);
            Context::set('b', 99);
            Context::copyFrom(2);
            // Copied value is present.
            $this->assertSame(42, Context::get('a'));
            // Context::copyFrom() merges — existing values are preserved.
            $this->assertSame(99, Context::get('b'));
        }, function () {
            CoroutineContext::set(Context::FD, 3);
            Context::set('b', 99);
            Context::copyFrom(2, ['a']);
            // Copied value is present.
            $this->assertSame(42, Context::get('a'));
            // Context::copyFrom() merges — existing values are preserved.
            $this->assertSame(99, Context::get('b'));
        }]);
    }

    public function testCopyFromCopiesFilteredDottedAndNullValuesWithoutRemovingSiblings(): void
    {
        CoroutineContext::set(Context::FD, 2);
        Context::set('profile.name', 'Taylor');
        Context::set('profile.nickname', null);

        CoroutineContext::set(Context::FD, 3);
        Context::set('profile.email', 'taylor@example.com');
        Context::copyFrom(2, ['profile.name', 'profile.nickname', 'profile.missing']);

        $this->assertSame('Taylor', Context::get('profile.name'));
        $this->assertTrue(Context::has('profile.nickname'));
        $this->assertNull(Context::get('profile.nickname'));
        $this->assertFalse(Context::has('profile.missing'));
        $this->assertSame('taylor@example.com', Context::get('profile.email'));
    }

    public function testCopyFromMissingSourcePreservesExistingValues(): void
    {
        CoroutineContext::set(Context::FD, 3);
        Context::set('existing', 99);

        Context::copyFrom(999);

        $this->assertSame(99, Context::get('existing'));
        $this->assertSame(['existing' => 99], Context::getStorage()[3]);
    }

    public function testOverride(): void
    {
        Context::set('override.id', 1);
        $this->assertSame(2, Context::override('override.id', function ($id) {
            return $id + 1;
        }));

        $this->assertSame(2, Context::get('override.id'));
    }

    public function testGetOrSet(): void
    {
        Context::set('test.store.id', null);

        $this->assertNull(Context::getOrSet('test.store.id', function () {
            return 1;
        }));

        Context::forget('test.store.id');

        $this->assertSame(1, Context::getOrSet('test.store.id', function () {
            return 1;
        }));
        $this->assertSame(1, Context::getOrSet('test.store.id', 2));
    }
}
