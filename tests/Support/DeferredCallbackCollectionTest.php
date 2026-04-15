<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DeferredCallbackCollectionTest extends TestCase
{
    public function testForgetRemovesCallbacksByName()
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'alpha';
        }, 'alpha');
        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'beta';
        }, 'beta');

        $callbacks->forget('alpha');
        $callbacks->invoke();

        $this->assertSame(['beta'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testInvokeDeduplicatesCallbacksByName()
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'first';
        }, 'metrics');
        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'second';
        }, 'metrics');
        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'other';
        }, 'other');

        $callbacks->invoke();

        $this->assertSame(['second', 'other'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testInvokeWhenHonorsPredicateAndStillClearsCollection()
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'skipped';
        }, 'skip');
        $callbacks[] = new DeferredCallback(function () use (&$results) {
            $results[] = 'run';
        }, 'run', true);

        $callbacks->invokeWhen(fn (DeferredCallback $callback) => $callback->always);

        $this->assertSame(['run'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testCountReturnsDeduplicatedViewBeforeInvoke()
    {
        $callbacks = new DeferredCallbackCollection;

        $callbacks[] = new DeferredCallback(fn () => null, 'metrics');
        $callbacks[] = new DeferredCallback(fn () => null, 'metrics');
        $callbacks[] = new DeferredCallback(fn () => null, 'other');

        $this->assertCount(2, $callbacks);
    }

    public function testOffsetExistsUsesDeduplicatedView()
    {
        $callbacks = new DeferredCallbackCollection;

        $callbacks[] = new DeferredCallback(fn () => null, 'a');
        $callbacks[] = new DeferredCallback(fn () => null, 'a');
        $callbacks[] = new DeferredCallback(fn () => null, 'b');

        $this->assertTrue(isset($callbacks[0]));
        $this->assertTrue(isset($callbacks[1]));
        $this->assertFalse(isset($callbacks[2]));
    }

    public function testOffsetGetReturnsDeduplicatedLastOccurrence()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $first = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'first';
        }, 'alpha');

        $second = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'second';
        }, 'alpha');

        $callbacks[] = $first;
        $callbacks[] = $second;

        $retrieved = $callbacks[0];

        $this->assertInstanceOf(DeferredCallback::class, $retrieved);
        $this->assertSame($second, $retrieved);

        ($retrieved)();
        $this->assertSame(['second'], $ran);
    }

    public function testOffsetUnsetOperatesOnDeduplicatedView()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'a-first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'a-second';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'b';
        }, 'b');

        unset($callbacks[0]);

        $callbacks->invoke();

        $this->assertSame(['b'], $ran);
    }

    public function testExplicitIndexedOffsetSetTriggersLaterDedupe()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'first';
        }, 'alpha');

        // Force a dedupe pass so $needsDedupe is cleared.
        $this->assertCount(1, $callbacks);

        // Explicit-index write must re-flag the collection dirty even though
        // $needsDedupe is currently false.
        $callbacks[1] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'second';
        }, 'alpha');

        $callbacks->invoke();

        $this->assertSame(['second'], $ran);
    }

    public function testMutationAfterReadReflagsCollection()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'second';
        }, 'a');

        $this->assertCount(1, $callbacks);

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'third';
        }, 'a');

        $this->assertCount(1, $callbacks);

        $callbacks->invoke();

        $this->assertSame(['third'], $ran);
    }

    public function testForgetAfterMutationLeavesCleanState()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'a-first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'a-second';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'b-first';
        }, 'b');

        $callbacks->forget('a');

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'b-second';
        }, 'b');

        $callbacks->invoke();

        $this->assertSame(['b-second'], $ran);
    }

    public function testForgetPreservesDedupeFlagWhenOtherDuplicatesRemain()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'a';
        }, 'alpha');

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'b-first';
        }, 'bravo');

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'b-second';
        }, 'bravo');

        $callbacks->forget('alpha');

        // No intervening write — the next read must still dedupe the two bravos.
        $this->assertCount(1, $callbacks);

        $callbacks->invoke();

        $this->assertSame(['b-second'], $ran);
    }

    public function testFirstReturnsDeduplicatedView()
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'first';
        }, 'alpha');

        $last = new DeferredCallback(function () use (&$ran) {
            $ran[] = 'last';
        }, 'alpha');

        $callbacks[] = $last;

        $first = $callbacks->first();

        $this->assertSame($last, $first);
        $this->assertSame($callbacks[0], $first);

        ($first)();
        $this->assertSame(['last'], $ran);
    }
}
