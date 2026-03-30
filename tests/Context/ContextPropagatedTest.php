<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\PropagatedContext;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ContextPropagatedTest extends TestCase
{
    public function testPropagatedReturnsPropagatedContextInstance()
    {
        $this->assertInstanceOf(PropagatedContext::class, CoroutineContext::propagated());
    }

    public function testPropagatedReturnsSameInstanceWithinContext()
    {
        $first = CoroutineContext::propagated();
        $second = CoroutineContext::propagated();

        $this->assertSame($first, $second);
    }

    public function testPropagatedCanBeStoredInVariable()
    {
        $propagated = CoroutineContext::propagated();
        $propagated->add('key', 'value');

        $this->assertSame('value', CoroutineContext::propagated()->get('key'));
    }

    public function testFlushClearsPropagatedContext()
    {
        CoroutineContext::propagated()->add('key', 'value');
        $this->assertTrue(CoroutineContext::hasPropagated());

        CoroutineContext::flush();

        // After flush, hasPropagated returns false and a new call
        // to propagated() returns a fresh empty instance
        $this->assertFalse(CoroutineContext::hasPropagated());
        $this->assertNull(CoroutineContext::propagated()->get('key'));
    }

    public function testPropagatedAddAndGet()
    {
        CoroutineContext::propagated()->add('key', 'val');

        $this->assertSame('val', CoroutineContext::propagated()->get('key'));
    }

    public function testPropagatedDataDoesNotAppearInRawContext()
    {
        CoroutineContext::propagated()->add('key', 'val');

        $this->assertNull(CoroutineContext::get('key'));
    }

    public function testRawContextDataDoesNotAppearInPropagated()
    {
        CoroutineContext::set('key', 'val');

        $this->assertNull(CoroutineContext::propagated()->get('key'));
    }

    public function testHasPropagatedReturnsFalseWhenNeverAccessed()
    {
        $this->assertFalse(CoroutineContext::hasPropagated());
    }

    public function testHasPropagatedReturnsTrueAfterPropagatedAccessed()
    {
        CoroutineContext::propagated();

        $this->assertTrue(CoroutineContext::hasPropagated());
    }

    public function testHasPropagatedDoesNotCreateInstance()
    {
        // First call should not create an instance
        $this->assertFalse(CoroutineContext::hasPropagated());

        // Second call should still be false — no instance was created
        $this->assertFalse(CoroutineContext::hasPropagated());
    }
}
