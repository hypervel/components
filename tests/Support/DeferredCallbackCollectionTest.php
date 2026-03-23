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
        $callbacks = new DeferredCallbackCollection();
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
        $callbacks = new DeferredCallbackCollection();
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
        $callbacks = new DeferredCallbackCollection();
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
}
