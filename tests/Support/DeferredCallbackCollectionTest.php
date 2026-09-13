<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Tests\TestCase;

class DeferredCallbackCollectionTest extends TestCase
{
    public function testForgetRemovesCallbacksByName(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'alpha';
        }, 'alpha');
        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'beta';
        }, 'beta');

        $callbacks->forget('alpha');
        $callbacks->invoke();

        $this->assertSame(['beta'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testInvokeDeduplicatesCallbacksByName(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'first';
        }, 'metrics');
        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'second';
        }, 'metrics');
        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'other';
        }, 'other');

        $callbacks->invoke();

        $this->assertSame(['second', 'other'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testInvokeWhenHonorsPredicateAndStillClearsCollection(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $results = [];

        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'skipped';
        }, 'skip');
        $callbacks[] = new DeferredCallback(function () use (&$results): void {
            $results[] = 'run';
        }, 'run', true);

        $callbacks->invokeWhen(fn (DeferredCallback $callback): bool => $callback->always);

        $this->assertSame(['run'], $results);
        $this->assertCount(0, $callbacks);
    }

    public function testCountReturnsDeduplicatedViewBeforeInvoke(): void
    {
        $callbacks = new DeferredCallbackCollection;

        $callbacks[] = new DeferredCallback(fn (): null => null, 'metrics');
        $callbacks[] = new DeferredCallback(fn (): null => null, 'metrics');
        $callbacks[] = new DeferredCallback(fn (): null => null, 'other');

        $this->assertCount(2, $callbacks);
    }

    public function testOffsetExistsUsesDeduplicatedView(): void
    {
        $callbacks = new DeferredCallbackCollection;

        $callbacks[] = new DeferredCallback(fn (): null => null, 'a');
        $callbacks[] = new DeferredCallback(fn (): null => null, 'a');
        $callbacks[] = new DeferredCallback(fn (): null => null, 'b');

        $this->assertTrue(isset($callbacks[0]));
        $this->assertTrue(isset($callbacks[1]));
        $this->assertFalse(isset($callbacks[2]));
    }

    public function testOffsetGetReturnsDeduplicatedLastOccurrence(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $first = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'first';
        }, 'alpha');

        $second = new DeferredCallback(function () use (&$ran): void {
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

    public function testOffsetUnsetOperatesOnDeduplicatedView(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'a-first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'a-second';
        }, 'a');
        $remaining = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'b';
        }, 'b');
        $callbacks[] = $remaining;

        unset($callbacks[0]);

        $this->assertSame($remaining, $callbacks[0]);
        $this->assertFalse(isset($callbacks[1]));

        $callbacks->invoke();

        $this->assertSame(['b'], $ran);
    }

    public function testExplicitIndexedOffsetSetTriggersLaterDedupe(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'first';
        }, 'alpha');

        $this->assertCount(1, $callbacks);

        $callbacks[1] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'second';
        }, 'alpha');

        $callbacks->invoke();

        $this->assertSame(['second'], $ran);
    }

    public function testAppendingAfterReadDeduplicatesCallbacks(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'second';
        }, 'a');

        $this->assertCount(1, $callbacks);

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'third';
        }, 'a');

        $this->assertCount(1, $callbacks);

        $callbacks->invoke();

        $this->assertSame(['third'], $ran);
    }

    public function testRenamingAfterReadDeduplicatesCallbacks(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'original';
        }, 'refresh');
        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'replacement';
        }, 'other');

        $callbacks[1]->name('refresh');

        $this->assertCount(1, $callbacks);

        $callbacks->invoke();

        $this->assertSame(['replacement'], $ran);
    }

    public function testForgetAfterMutationLeavesCleanState(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'a-first';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'a-second';
        }, 'a');
        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'b-first';
        }, 'b');

        $callbacks->forget('a');

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'b-second';
        }, 'b');

        $callbacks->invoke();

        $this->assertSame(['b-second'], $ran);
    }

    public function testForgetStillDeduplicatesRemainingCallbacks(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'a';
        }, 'alpha');

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'b-first';
        }, 'bravo');

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'b-second';
        }, 'bravo');

        $callbacks->forget('alpha');

        $this->assertCount(1, $callbacks);

        $callbacks->invoke();

        $this->assertSame(['b-second'], $ran);
    }

    public function testFirstReturnsDeduplicatedView(): void
    {
        $callbacks = new DeferredCallbackCollection;
        $ran = [];

        $callbacks[] = new DeferredCallback(function () use (&$ran): void {
            $ran[] = 'first';
        }, 'alpha');

        $last = new DeferredCallback(function () use (&$ran): void {
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
