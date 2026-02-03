<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Meilisearch;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\SearchableModel;

/**
 * Integration tests for MeilisearchEngine core operations.
 *
 * @group integration
 * @group meilisearch-integration
 *
 * @internal
 * @coversNothing
 */
class MeilisearchEngineIntegrationTest extends MeilisearchScoutIntegrationTestCase
{
    public function testUpdateIndexesModelsInMeilisearch(): void
    {
        $model = SearchableModel::create(['title' => 'Test Document', 'body' => 'Content here']);

        $this->engine->update(new EloquentCollection([$model]));
        $this->waitForMeilisearchTasks();

        $results = $this->meilisearch->index($model->searchableAs())->search('Test');

        $this->assertCount(1, $results->getHits());
        $this->assertSame('Test Document', $results->getHits()[0]['title']);
    }

    public function testUpdateWithMultipleModels(): void
    {
        $models = new EloquentCollection([
            SearchableModel::create(['title' => 'First', 'body' => 'Body 1']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body 2']),
            SearchableModel::create(['title' => 'Third', 'body' => 'Body 3']),
        ]);

        $this->engine->update($models);
        $this->waitForMeilisearchTasks();

        $results = $this->meilisearch->index($models->first()->searchableAs())->search('');

        $this->assertCount(3, $results->getHits());
    }

    public function testDeleteRemovesModelsFromMeilisearch(): void
    {
        $model = SearchableModel::create(['title' => 'To Delete', 'body' => 'Content']);

        $this->engine->update(new EloquentCollection([$model]));
        $this->waitForMeilisearchTasks();

        // Verify it exists
        $results = $this->meilisearch->index($model->searchableAs())->search('Delete');
        $this->assertCount(1, $results->getHits());

        // Delete it
        $this->engine->delete(new EloquentCollection([$model]));
        $this->waitForMeilisearchTasks();

        // Verify it's gone
        $results = $this->meilisearch->index($model->searchableAs())->search('Delete');
        $this->assertCount(0, $results->getHits());
    }

    public function testSearchReturnsMatchingResults(): void
    {
        SearchableModel::create(['title' => 'PHP Programming', 'body' => 'Learn PHP']);
        SearchableModel::create(['title' => 'JavaScript Guide', 'body' => 'Learn JS']);
        SearchableModel::create(['title' => 'PHP Best Practices', 'body' => 'Advanced PHP']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('PHP')->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('title', 'PHP Programming'));
        $this->assertTrue($results->contains('title', 'PHP Best Practices'));
    }

    public function testSearchWithEmptyQueryReturnsAllDocuments(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $results = SearchableModel::search('')->get();

        $this->assertCount(2, $results);
    }

    public function testPaginateReturnsCorrectPage(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $page1 = SearchableModel::search('')->paginate(3, 'page', 1);
        $page2 = SearchableModel::search('')->paginate(3, 'page', 2);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertSame(10, $page1->total());
    }

    public function testFlushRemovesAllDocumentsFromIndex(): void
    {
        $models = new EloquentCollection([
            SearchableModel::create(['title' => 'First', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);
        $this->waitForMeilisearchTasks();

        // Verify documents exist
        $results = $this->meilisearch->index($models->first()->searchableAs())->search('');
        $this->assertCount(2, $results->getHits());

        // Flush
        $this->engine->flush($models->first());
        $this->waitForMeilisearchTasks();

        // Verify empty
        $results = $this->meilisearch->index($models->first()->searchableAs())->search('');
        $this->assertCount(0, $results->getHits());
    }

    public function testCreateIndexCreatesNewIndex(): void
    {
        $indexName = $this->prefixedIndexName('new_index');

        $this->engine->createIndex($indexName, ['primaryKey' => 'id']);
        $this->waitForMeilisearchTasks();

        $index = $this->meilisearch->getIndex($indexName);

        $this->assertSame($indexName, $index->getUid());
    }

    public function testDeleteIndexRemovesIndex(): void
    {
        $indexName = $this->prefixedIndexName('to_delete');

        $this->engine->createIndex($indexName);
        $this->waitForMeilisearchTasks();

        $this->engine->deleteIndex($indexName);
        $this->waitForMeilisearchTasks();

        $this->expectException(\Meilisearch\Exceptions\ApiException::class);
        $this->meilisearch->getIndex($indexName);
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        SearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));
        $this->waitForMeilisearchTasks();

        $builder = SearchableModel::search('');
        $results = $this->engine->search($builder);

        $this->assertSame(5, $this->engine->getTotalCount($results));
    }

    public function testMapIdsReturnsCollectionOfIds(): void
    {
        $models = new EloquentCollection([
            SearchableModel::create(['title' => 'First', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);
        $this->waitForMeilisearchTasks();

        $builder = SearchableModel::search('');
        $results = $this->engine->search($builder);
        $ids = $this->engine->mapIds($results);

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($models[0]->id));
        $this->assertTrue($ids->contains($models[1]->id));
    }

    public function testKeysReturnsScoutKeys(): void
    {
        $models = new EloquentCollection([
            SearchableModel::create(['title' => 'First', 'body' => 'Body']),
            SearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);
        $this->waitForMeilisearchTasks();

        $keys = SearchableModel::search('')->keys();

        $this->assertCount(2, $keys);
    }
}
