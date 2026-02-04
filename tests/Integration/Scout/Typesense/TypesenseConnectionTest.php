<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\Support\TypesenseIntegrationTestCase;

/**
 * Basic connectivity test for Typesense.
 *
 * @internal
 * @coversNothing
 */
class TypesenseConnectionTest extends TypesenseIntegrationTestCase
{
    use RunTestsInCoroutine;

    protected function setUpInCoroutine(): void
    {
        $this->initializeTypesense();
    }

    public function testCanConnectToTypesense(): void
    {
        $health = $this->typesense->health->retrieve();

        $this->assertTrue($health['ok']);
    }

    public function testCanCreateAndDeleteCollection(): void
    {
        $collectionName = $this->prefixedCollectionName('test_collection');

        // Create collection
        $this->typesense->collections->create([
            'name' => $collectionName,
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);

        // Verify it exists
        $collection = $this->typesense->collections[$collectionName]->retrieve();
        $this->assertSame($collectionName, $collection['name']);

        // Delete it
        $this->typesense->collections[$collectionName]->delete();
    }

    public function testCanIndexAndSearchDocuments(): void
    {
        $collectionName = $this->prefixedCollectionName('search_test');

        // Create collection
        $this->typesense->collections->create([
            'name' => $collectionName,
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);

        $collection = $this->typesense->collections[$collectionName];

        // Add documents
        $collection->documents->create(['id' => '1', 'title' => 'Hello World']);
        $collection->documents->create(['id' => '2', 'title' => 'Goodbye World']);

        // Search
        $results = $collection->documents->search([
            'q' => 'Hello',
            'query_by' => 'title',
        ]);

        $this->assertSame(1, $results['found']);
        $this->assertSame('Hello World', $results['hits'][0]['document']['title']);

        // Cleanup
        $this->typesense->collections[$collectionName]->delete();
    }
}
