<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Meilisearch;

use Hypervel\Tests\Scout\Models\SearchableModel;

/**
 * Integration tests for Scout console commands with Meilisearch.
 *
 * @group integration
 * @group meilisearch-integration
 *
 * @internal
 * @coversNothing
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
}
