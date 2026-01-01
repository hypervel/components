<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Meilisearch;

use Hypervel\Tests\Support\MeilisearchIntegrationTestCase;

/**
 * Basic connectivity test for Meilisearch.
 *
 * @group integration
 * @group meilisearch-integration
 *
 * @internal
 * @coversNothing
 */
class MeilisearchConnectionTest extends MeilisearchIntegrationTestCase
{
    public function testCanConnectToMeilisearch(): void
    {
        $health = $this->meilisearch->health();

        $this->assertSame('available', $health['status']);
    }

    public function testCanCreateAndDeleteIndex(): void
    {
        $indexName = $this->prefixedIndexName('test_index');

        // Create index
        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        // Verify it exists
        $index = $this->meilisearch->getIndex($indexName);
        $this->assertSame($indexName, $index->getUid());

        // Delete it
        $this->meilisearch->deleteIndex($indexName);
    }

    public function testCanIndexAndSearchDocuments(): void
    {
        $indexName = $this->prefixedIndexName('search_test');

        // Create index
        $task = $this->meilisearch->createIndex($indexName, ['primaryKey' => 'id']);
        $this->meilisearch->waitForTask($task['taskUid']);

        $index = $this->meilisearch->index($indexName);

        // Add documents
        $task = $index->addDocuments([
            ['id' => 1, 'title' => 'Hello World'],
            ['id' => 2, 'title' => 'Goodbye World'],
        ]);
        $this->meilisearch->waitForTask($task['taskUid']);

        // Search
        $results = $index->search('Hello');

        $this->assertCount(1, $results->getHits());
        $this->assertSame('Hello World', $results->getHits()[0]['title']);

        // Cleanup
        $this->meilisearch->deleteIndex($indexName);
    }
}
