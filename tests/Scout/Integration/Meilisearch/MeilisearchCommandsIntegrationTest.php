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
        // Create models in the database
        SearchableModel::create(['title' => 'First Document', 'body' => 'Content']);
        SearchableModel::create(['title' => 'Second Document', 'body' => 'Content']);
        SearchableModel::create(['title' => 'Third Document', 'body' => 'Content']);

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
        // Create and index models
        SearchableModel::create(['title' => 'First', 'body' => 'Content']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Content']);

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
}
