<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectManager;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AspectManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        AspectManager::flushState();

        parent::tearDown();
    }

    public function testHasReturnsFalseForUnsetEntry()
    {
        $this->assertFalse(AspectManager::has('Foo', 'bar'));
    }

    public function testSetAndGet()
    {
        AspectManager::set('Foo', 'bar', ['Aspect1', 'Aspect2']);

        $this->assertTrue(AspectManager::has('Foo', 'bar'));
        $this->assertSame(['Aspect1', 'Aspect2'], AspectManager::get('Foo', 'bar'));
    }

    public function testGetReturnsEmptyArrayForUnsetEntry()
    {
        $this->assertSame([], AspectManager::get('Foo', 'bar'));
    }

    public function testInsertAppendsToList()
    {
        AspectManager::set('Foo', 'bar', []);
        AspectManager::insert('Foo', 'bar', 'Aspect1');
        AspectManager::insert('Foo', 'bar', 'Aspect2');

        $this->assertSame(['Aspect1', 'Aspect2'], AspectManager::get('Foo', 'bar'));
    }

    public function testFlushStateRemovesAllEntries()
    {
        AspectManager::set('Foo', 'bar', ['Aspect1']);
        AspectManager::set('Baz', 'qux', ['Aspect2']);

        AspectManager::flushState();

        $this->assertFalse(AspectManager::has('Foo', 'bar'));
        $this->assertFalse(AspectManager::has('Baz', 'qux'));
    }
}
