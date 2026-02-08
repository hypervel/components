<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Meilisearch;

use Hypervel\Tests\Scout\Models\SearchableModel;

/**
 * Integration tests for Meilisearch index settings configuration.
 *
 * @internal
 * @coversNothing
 */
class MeilisearchIndexSettingsIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    public function testSyncIndexSettingsCommandAppliesConfigSettings(): void
    {
        $indexName = $this->prefixedIndexName('searchable_models');

        // Create the index first
        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        // Configure index settings via Scout config
        $this->app->get('config')->set('scout.meilisearch.index-settings', [
            SearchableModel::class => [
                'filterableAttributes' => ['title', 'body'],
                'sortableAttributes' => ['id', 'title'],
                'searchableAttributes' => ['title', 'body'],
            ],
        ]);

        // Run the sync command
        $this->artisan('scout:sync-index-settings')
            ->assertOk()
            ->expectsOutputToContain('synced successfully');

        $this->waitForMeilisearchTasks();

        // Verify the settings were applied
        $index = $this->meilisearch->index($indexName);
        $settings = $index->getSettings();

        $this->assertContains('title', $settings['filterableAttributes']);
        $this->assertContains('body', $settings['filterableAttributes']);
        $this->assertContains('id', $settings['sortableAttributes']);
        $this->assertContains('title', $settings['sortableAttributes']);
        $this->assertEquals(['title', 'body'], $settings['searchableAttributes']);
    }

    public function testSyncIndexSettingsCommandWithPlainIndexName(): void
    {
        $indexName = $this->prefixedIndexName('custom_index');

        // Create the index first
        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        // Configure index settings using plain index name (with prefix)
        $this->app->get('config')->set('scout.meilisearch.index-settings', [
            $indexName => [
                'filterableAttributes' => ['status'],
                'sortableAttributes' => ['created_at'],
            ],
        ]);

        // Run the sync command
        $this->artisan('scout:sync-index-settings')
            ->assertOk();

        $this->waitForMeilisearchTasks();

        // Verify the settings were applied
        $index = $this->meilisearch->index($indexName);
        $settings = $index->getSettings();

        $this->assertContains('status', $settings['filterableAttributes']);
        $this->assertContains('created_at', $settings['sortableAttributes']);
    }

    public function testSyncIndexSettingsCommandReportsNoSettingsWhenEmpty(): void
    {
        // Ensure no index settings are configured
        $this->app->get('config')->set('scout.meilisearch.index-settings', []);

        // Run the sync command
        $this->artisan('scout:sync-index-settings')
            ->assertOk()
            ->expectsOutputToContain('No index settings found');
    }
}
