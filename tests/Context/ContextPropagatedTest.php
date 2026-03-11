<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\Context;
use Hypervel\Context\PropagatedContext;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ContextPropagatedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();
    }

    protected function tearDown(): void
    {
        Context::flush();

        parent::tearDown();
    }

    public function testPropagatedReturnsPropagatedContextInstance()
    {
        $this->assertInstanceOf(PropagatedContext::class, Context::propagated());
    }

    public function testPropagatedReturnsSameInstanceWithinContext()
    {
        $first = Context::propagated();
        $second = Context::propagated();

        $this->assertSame($first, $second);
    }

    public function testPropagatedCanBeStoredInVariable()
    {
        $propagated = Context::propagated();
        $propagated->add('key', 'value');

        $this->assertSame('value', Context::propagated()->get('key'));
    }

    public function testFlushClearsPropagatedContext()
    {
        Context::propagated()->add('key', 'value');
        $this->assertTrue(Context::hasPropagated());

        Context::flush();

        // After flush, hasPropagated returns false and a new call
        // to propagated() returns a fresh empty instance
        $this->assertFalse(Context::hasPropagated());
        $this->assertNull(Context::propagated()->get('key'));
    }

    public function testPropagatedAddAndGet()
    {
        Context::propagated()->add('key', 'val');

        $this->assertSame('val', Context::propagated()->get('key'));
    }

    public function testPropagatedDataDoesNotAppearInRawContext()
    {
        Context::propagated()->add('key', 'val');

        $this->assertNull(Context::get('key'));
    }

    public function testRawContextDataDoesNotAppearInPropagated()
    {
        Context::set('key', 'val');

        $this->assertNull(Context::propagated()->get('key'));
    }

    public function testHasPropagatedReturnsFalseWhenNeverAccessed()
    {
        $this->assertFalse(Context::hasPropagated());
    }

    public function testHasPropagatedReturnsTrueAfterPropagatedAccessed()
    {
        Context::propagated();

        $this->assertTrue(Context::hasPropagated());
    }

    public function testHasPropagatedDoesNotCreateInstance()
    {
        // First call should not create an instance
        $this->assertFalse(Context::hasPropagated());

        // Second call should still be false — no instance was created
        $this->assertFalse(Context::hasPropagated());
    }

    // =========================================================================
    // context() helper — propagated boundary
    // =========================================================================

    public function testContextHelperGetDoesNotReadPropagated()
    {
        Context::propagated()->add('key', 'val');

        $this->assertNull(context('key'));
    }
}
