<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

use function Hypervel\Coroutine\go;

/**
 * Tests that Scout's sync disable mechanism is coroutine-safe.
 *
 * The Searchable trait uses Context::set/get for per-coroutine state,
 * ensuring that disabling syncing in one coroutine doesn't affect
 * other concurrent coroutines.
 *
 * @internal
 * @coversNothing
 */
class CoroutineSafetyTest extends ScoutTestCase
{
    public function testDisableSyncingIsCoroutineIsolated()
    {
        // Initially syncing is enabled
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());

        $results = [];
        $waiter = new WaitGroup();

        // Coroutine 1: Disable syncing
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            SearchableModel::disableSearchSyncing();
            usleep(10000); // 10ms - let other coroutine start

            $results['coroutine1'] = SearchableModel::isSearchSyncingEnabled();
            $waiter->done();
        });

        // Coroutine 2: Check syncing (should still be enabled in its context)
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            usleep(5000); // 5ms - start after coroutine 1 disables syncing

            $results['coroutine2'] = SearchableModel::isSearchSyncingEnabled();
            $waiter->done();
        });

        $waiter->wait();

        // Coroutine 1 should have syncing disabled (it called disableSearchSyncing)
        $this->assertFalse($results['coroutine1']);

        // Coroutine 2 should have syncing enabled (Context is isolated)
        $this->assertTrue($results['coroutine2']);
    }

    public function testWithoutSyncingToSearchIsCoroutineIsolated()
    {
        $results = [];
        $waiter = new WaitGroup();

        // Coroutine 1: Run without syncing
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            SearchableModel::withoutSyncingToSearch(function () use (&$results) {
                usleep(10000); // 10ms
                $results['inside_callback'] = SearchableModel::isSearchSyncingEnabled();
            });

            $results['after_callback'] = SearchableModel::isSearchSyncingEnabled();
            $waiter->done();
        });

        // Coroutine 2: Check syncing during callback execution
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            usleep(5000); // 5ms - check during callback

            $results['concurrent'] = SearchableModel::isSearchSyncingEnabled();
            $waiter->done();
        });

        $waiter->wait();

        // Inside callback, syncing should be disabled
        $this->assertFalse($results['inside_callback']);

        // After callback, syncing should be restored
        $this->assertTrue($results['after_callback']);

        // Concurrent coroutine should have syncing enabled
        $this->assertTrue($results['concurrent']);
    }

    public function testMultipleConcurrentDisableSync()
    {
        $results = [];
        $waiter = new WaitGroup();

        // Create multiple coroutines that each toggle syncing
        for ($i = 0; $i < 5; ++$i) {
            $waiter->add(1);
            $coroutineId = $i;

            go(function () use (&$results, $waiter, $coroutineId) {
                // Record initial state
                $results["before_{$coroutineId}"] = SearchableModel::isSearchSyncingEnabled();

                // Only disable for even coroutines
                if ($coroutineId % 2 === 0) {
                    SearchableModel::disableSearchSyncing();
                }

                usleep(1000 * ($coroutineId + 1)); // Stagger execution

                $results["after_{$coroutineId}"] = SearchableModel::isSearchSyncingEnabled();
                $waiter->done();
            });
        }

        $waiter->wait();

        // All coroutines should start with syncing enabled (fresh context)
        for ($i = 0; $i < 5; ++$i) {
            $this->assertTrue(
                $results["before_{$i}"],
                "Coroutine {$i} should start with syncing enabled"
            );
        }

        // Even coroutines should have syncing disabled, odd should have enabled
        for ($i = 0; $i < 5; ++$i) {
            if ($i % 2 === 0) {
                $this->assertFalse(
                    $results["after_{$i}"],
                    "Even coroutine {$i} should have syncing disabled"
                );
            } else {
                $this->assertTrue(
                    $results["after_{$i}"],
                    "Odd coroutine {$i} should have syncing enabled"
                );
            }
        }
    }

    public function testNestedCoroutinesHaveIsolatedContext()
    {
        $results = [];
        $waiter = new WaitGroup();

        // Parent coroutine
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            SearchableModel::disableSearchSyncing();
            $results['parent_before_child'] = SearchableModel::isSearchSyncingEnabled();

            $childWaiter = new WaitGroup();

            // Nested child coroutine
            $childWaiter->add(1);
            go(function () use (&$results, $childWaiter) {
                // Child has its own fresh context
                $results['child'] = SearchableModel::isSearchSyncingEnabled();
                $childWaiter->done();
            });

            $childWaiter->wait();

            $results['parent_after_child'] = SearchableModel::isSearchSyncingEnabled();
            $waiter->done();
        });

        $waiter->wait();

        // Parent should have syncing disabled (it called disableSearchSyncing)
        $this->assertFalse($results['parent_before_child']);
        $this->assertFalse($results['parent_after_child']);

        // Child should have syncing enabled (nested coroutine has fresh context)
        $this->assertTrue($results['child']);
    }
}
