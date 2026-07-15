<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectManager;
use Hypervel\Tests\TestCase;

class AspectManagerTest extends TestCase
{
    public function testHasReturnsFalseForUnsetEntry(): void
    {
        $this->assertFalse(AspectManager::has('Foo', 'bar'));
    }

    public function testSetAndGet(): void
    {
        AspectManager::set('Foo', 'bar', ['Aspect1', 'Aspect2']);

        $this->assertTrue(AspectManager::has('Foo', 'bar'));
        $this->assertSame(['Aspect1', 'Aspect2'], AspectManager::get('Foo', 'bar'));
    }

    public function testGetReturnsEmptyArrayForUnsetEntry(): void
    {
        $this->assertSame([], AspectManager::get('Foo', 'bar'));
    }

    public function testFlushStateRemovesAllEntries(): void
    {
        AspectManager::set('Foo', 'bar', ['Aspect1']);
        AspectManager::set('Baz', 'qux', ['Aspect2']);

        AspectManager::flushState();

        $this->assertFalse(AspectManager::has('Foo', 'bar'));
        $this->assertFalse(AspectManager::has('Baz', 'qux'));
    }
}
