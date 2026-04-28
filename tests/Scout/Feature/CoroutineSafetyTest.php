<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Scout;
use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use RuntimeException;

use function Hypervel\Coroutine\go;

/**
 * Tests that Scout's sync disable mechanism is coroutine-safe.
 *
 * The Searchable trait uses Context::set/get for per-coroutine state,
 * ensuring that disabling syncing in one coroutine doesn't affect
 * other concurrent coroutines.
 */
class CoroutineSafetyTest extends ScoutTestCase
{
    public function testDisableSyncingIsCoroutineIsolated()
    {
        // Initially syncing is enabled
        $this->assertTrue(SearchableModel::isSearchSyncingEnabled());

        $results = [];
        $waiter = new WaitGroup;

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
        $waiter = new WaitGroup;

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
        $waiter = new WaitGroup;

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
        $waiter = new WaitGroup;

        // Parent coroutine
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            SearchableModel::disableSearchSyncing();
            $results['parent_before_child'] = SearchableModel::isSearchSyncingEnabled();

            $childWaiter = new WaitGroup;

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

    public function testWhileImportingIsCoroutineIsolated()
    {
        $results = [];
        $waiter = new WaitGroup;

        // Coroutine 1: enter whileImporting, hold the flag for 10ms
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            Scout::whileImporting(function () use (&$results) {
                $results['coroutine1_inside'] = Scout::isImporting();
                usleep(10000); // 10ms - overlap with coroutine 2's check
            });

            $results['coroutine1_after'] = Scout::isImporting();
            $waiter->done();
        });

        // Coroutine 2: check Scout::isImporting() while coroutine 1 is inside whileImporting
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            usleep(5000); // 5ms - start after coroutine 1 has entered whileImporting
            $results['coroutine2'] = Scout::isImporting();
            $waiter->done();
        });

        $waiter->wait();

        $this->assertTrue($results['coroutine1_inside']);
        $this->assertFalse($results['coroutine1_after']);
        $this->assertFalse($results['coroutine2']);
    }

    public function testWhileImportingRestoresStateAfterCallbackAndOnException()
    {
        // Normal-return path
        $beforeNormal = Scout::isImporting();
        $insideNormal = null;
        Scout::whileImporting(function () use (&$insideNormal) {
            $insideNormal = Scout::isImporting();
        });
        $afterNormal = Scout::isImporting();

        $this->assertFalse($beforeNormal);
        $this->assertTrue($insideNormal);
        $this->assertFalse($afterNormal);

        // Exception path
        $beforeException = Scout::isImporting();
        $insideException = null;
        try {
            Scout::whileImporting(function () use (&$insideException) {
                $insideException = Scout::isImporting();
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // swallow
        }
        $afterException = Scout::isImporting();

        $this->assertFalse($beforeException);
        $this->assertTrue($insideException);
        $this->assertFalse($afterException);
    }

    public function testQueueMakeSearchableBypassIsCoroutineIsolated()
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Bus::fake([MakeSearchable::class]);

        $waiter = new WaitGroup;

        // Coroutine A: queueMakeSearchable inside whileImporting (bypassed → no Bus dispatch)
        $waiter->add(1);
        go(function () use ($waiter) {
            Scout::whileImporting(function () {
                usleep(5000); // hold the importing flag while B dispatches
                $model = new SearchableModel(['title' => 'A', 'body' => 'Body']);
                $model->id = 1;
                $model->queueMakeSearchable(new Collection([$model]));
            });
            $waiter->done();
        });

        // Coroutine B: queueMakeSearchable outside whileImporting (queues normally → 1 Bus dispatch)
        $waiter->add(1);
        go(function () use ($waiter) {
            usleep(2000); // start while A is still inside whileImporting
            $model = new SearchableModel(['title' => 'B', 'body' => 'Body']);
            $model->id = 2;
            $model->queueMakeSearchable(new Collection([$model]));
            $waiter->done();
        });

        $waiter->wait();

        // Only B's dispatch goes through Bus; A's was bypassed because A's coroutine had the flag
        Bus::assertDispatchedTimes(MakeSearchable::class, 1);
    }

    public function testQueueRemoveFromSearchBypassIsCoroutineIsolated()
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Bus::fake([RemoveFromSearch::class]);

        $waiter = new WaitGroup;

        // Coroutine A: queueRemoveFromSearch inside whileImporting (bypassed → no Bus dispatch)
        $waiter->add(1);
        go(function () use ($waiter) {
            Scout::whileImporting(function () {
                usleep(5000);
                $model = new SearchableModel(['title' => 'A', 'body' => 'Body']);
                $model->id = 1;
                $model->queueRemoveFromSearch(new Collection([$model]));
            });
            $waiter->done();
        });

        // Coroutine B: queueRemoveFromSearch outside whileImporting (queues normally → 1 Bus dispatch)
        $waiter->add(1);
        go(function () use ($waiter) {
            usleep(2000);
            $model = new SearchableModel(['title' => 'B', 'body' => 'Body']);
            $model->id = 2;
            $model->queueRemoveFromSearch(new Collection([$model]));
            $waiter->done();
        });

        $waiter->wait();

        Bus::assertDispatchedTimes(RemoveFromSearch::class, 1);
    }

    public function testRunnerStateIsCoroutineIsolated()
    {
        // queue.enabled=false so dispatch goes through dispatchSearchableJob (which sets up the runner)
        $this->app->make('config')->set('scout.queue.enabled', false);

        $results = [];
        $waiter = new WaitGroup;

        // Coroutine A: trigger dispatchSearchableJob → runner gets installed in A's context
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            Scout::whileImporting(function () use (&$results) {
                $model = new SearchableModel(['title' => 'A', 'body' => 'Body']);
                $model->id = 1;
                $model->queueMakeSearchable(new Collection([$model]));
                $results['a_has_runner'] = CoroutineContext::has(SearchableModel::SCOUT_RUNNER_CONTEXT_KEY);
                usleep(10000); // hold runner in context while B checks
            });
            $waiter->done();
        });

        // Coroutine B: check whether A's runner is visible in B's context (it must not be)
        $waiter->add(1);
        go(function () use (&$results, $waiter) {
            usleep(5000); // start after A has installed its runner
            Scout::whileImporting(function () use (&$results) {
                $results['b_has_runner'] = CoroutineContext::has(SearchableModel::SCOUT_RUNNER_CONTEXT_KEY);
            });
            $waiter->done();
        });

        $waiter->wait();

        $this->assertTrue($results['a_has_runner']);
        $this->assertFalse($results['b_has_runner']);
    }

    public function testWhileImportingIsNestingSafe()
    {
        $results = [];

        $results['outside'] = Scout::isImporting();

        Scout::whileImporting(function () use (&$results) {
            $results['outer'] = Scout::isImporting();

            Scout::whileImporting(function () use (&$results) {
                $results['inner'] = Scout::isImporting();
            });

            $results['outer_after_inner'] = Scout::isImporting();
        });

        $results['after'] = Scout::isImporting();

        $this->assertFalse($results['outside']);
        $this->assertTrue($results['outer']);
        $this->assertTrue($results['inner']);
        $this->assertTrue($results['outer_after_inner']);
        $this->assertFalse($results['after']);
    }
}
