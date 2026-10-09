<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectManager;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Tests\TestCase;

class AspectManagerTest extends TestCase
{
    public function testSetAndGet(): void
    {
        $chain = static fn (ProceedingJoinPoint $point): mixed => $point->processOriginalMethod();
        AspectManager::set('Foo', 'bar', $chain);

        $this->assertSame($chain, AspectManager::get('Foo', 'bar'));
    }

    public function testGetReturnsNullForUnsetEntry(): void
    {
        $this->assertNull(AspectManager::get('Foo', 'bar'));
    }

    public function testFlushStateRemovesAllEntries(): void
    {
        $chain = static fn (ProceedingJoinPoint $point): mixed => $point->processOriginalMethod();
        AspectManager::set('Foo', 'bar', $chain);
        AspectManager::set('Baz', 'qux', $chain);

        AspectManager::flushState();

        $this->assertNull(AspectManager::get('Foo', 'bar'));
        $this->assertNull(AspectManager::get('Baz', 'qux'));
    }
}
