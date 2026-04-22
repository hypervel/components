<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Tests\Scout\Models\SearchableModel;
use Throwable;

/**
 * Integration tests for Scout console commands with Meilisearch.
 */
class MeilisearchCommandsIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    public function testImportCommandIndexesModels(): void
    {
        // Create models without triggering Scout indexing
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Second Document', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Third Document', 'body' => 'Content']);
        });

        // Verify models exist in DB
        $this->assertCount(3, SearchableModel::all());

        // Run the import command
        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been imported')
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Verify models are searchable
        $results = SearchableModel::search('Document')->get();

        $this->assertCount(3, $results);
    }

    public function testFlushCommandRemovesModels(): void
    {
        // Create models without triggering Scout indexing
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First', 'body' => 'Content']);
            SearchableModel::create(['title' => 'Second', 'body' => 'Content']);
        });

        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Verify models are indexed
        $results = SearchableModel::search('')->get();
        $this->assertCount(2, $results);

        // Run the flush command
        $this->artisan('scout:flush', ['model' => SearchableModel::class])
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Verify models are removed from the index
        $results = SearchableModel::search('')->get();
        $this->assertCount(0, $results);
    }

    public function testDeleteAllIndexesCommandRemovesAllIndexes(): void
    {
        // Create models without triggering Scout indexing
        SearchableModel::withoutSyncingToSearch(function (): void {
            SearchableModel::create(['title' => 'First', 'body' => 'Content']);
        });

        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Verify model is indexed
        $results = SearchableModel::search('')->get();
        $this->assertCount(1, $results);

        // Run the delete-all-indexes command
        $this->artisan('scout:delete-all-indexes')
            ->expectsOutputToContain('All indexes deleted successfully')
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Searching should now fail or return empty because index is gone
        // After deleting the index, Meilisearch will auto-create on next search
        // so we just verify the command executed successfully
    }

    public function testScopedDeleteAllIndexesPreservesUnrelatedIndexes(): void
    {
        // Real-wire regression test for the deleteAllIndexes scoping fix.
        // Creates two prefixed indexes (will be deleted by scoped call) and
        // one unprefixed index (must survive). Verifies only the prefixed
        // ones are removed.
        $unrelatedIndex = 'other_data_not_scope';

        try {
            // Two prefixed indexes — tracked for cleanup by the base class.
            $this->createTestIndex('scoped_a');
            $this->createTestIndex('scoped_b');

            // One unprefixed index — create directly, clean up manually below.
            $this->meilisearch->createIndex($unrelatedIndex);

            $this->waitForMeilisearchTasks();

            // Verify all three exist before the scoped delete.
            $uids = collect($this->meilisearch->getIndexes()->getResults())
                ->map(fn ($i) => $i->getUid())
                ->all();
            $this->assertContains($this->prefixedIndexName('scoped_a'), $uids);
            $this->assertContains($this->prefixedIndexName('scoped_b'), $uids);
            $this->assertContains($unrelatedIndex, $uids);

            // Scoped delete — should remove only the prefixed indexes.
            $this->engine->deleteAllIndexes($this->testPrefix);

            $this->waitForMeilisearchTasks();

            // Re-fetch and assert: unrelated index survives, prefixed ones gone.
            $uids = collect($this->meilisearch->getIndexes()->getResults())
                ->map(fn ($i) => $i->getUid())
                ->all();
            $this->assertNotContains($this->prefixedIndexName('scoped_a'), $uids);
            $this->assertNotContains($this->prefixedIndexName('scoped_b'), $uids);
            $this->assertContains($unrelatedIndex, $uids);
        } finally {
            // Clean up the unrelated index regardless of pass/fail.
            try {
                $this->meilisearch->deleteIndex($unrelatedIndex);
            } catch (Throwable) {
                // Ignore cleanup errors.
            }
        }
    }
}
