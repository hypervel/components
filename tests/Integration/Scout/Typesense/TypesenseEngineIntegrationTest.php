<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\TypesenseSearchableModel;

/**
 * Integration tests for TypesenseEngine core operations.
 *
 * @group integration
 * @group typesense-integration
 *
 * @internal
 * @coversNothing
 */
class TypesenseEngineIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    public function testUpdateIndexesModelsInTypesense(): void
    {
        $model = TypesenseSearchableModel::create(['title' => 'Test Document', 'body' => 'Content here']);

        $this->engine->update(new EloquentCollection([$model]));

        $results = $this->typesense->collections[$model->searchableAs()]->documents->search([
            'q' => 'Test',
            'query_by' => 'title',
        ]);

        $this->assertSame(1, $results['found']);
        $this->assertSame('Test Document', $results['hits'][0]['document']['title']);
    }

    public function testUpdateWithMultipleModels(): void
    {
        $models = new EloquentCollection([
            TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body 1']),
            TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body 2']),
            TypesenseSearchableModel::create(['title' => 'Third', 'body' => 'Body 3']),
        ]);

        $this->engine->update($models);

        $results = $this->typesense->collections[$models->first()->searchableAs()]->documents->search([
            'q' => '*',
            'query_by' => 'title',
        ]);

        $this->assertSame(3, $results['found']);
    }

    public function testDeleteRemovesModelsFromTypesense(): void
    {
        $model = TypesenseSearchableModel::create(['title' => 'To Delete', 'body' => 'Content']);

        $this->engine->update(new EloquentCollection([$model]));

        // Verify it exists
        $results = $this->typesense->collections[$model->searchableAs()]->documents->search([
            'q' => 'Delete',
            'query_by' => 'title',
        ]);
        $this->assertSame(1, $results['found']);

        // Delete it
        $this->engine->delete(new EloquentCollection([$model]));

        // Verify it's gone
        $results = $this->typesense->collections[$model->searchableAs()]->documents->search([
            'q' => 'Delete',
            'query_by' => 'title',
        ]);
        $this->assertSame(0, $results['found']);
    }

    public function testSearchReturnsMatchingResults(): void
    {
        TypesenseSearchableModel::create(['title' => 'PHP Programming', 'body' => 'Learn PHP']);
        TypesenseSearchableModel::create(['title' => 'JavaScript Guide', 'body' => 'Learn JS']);
        TypesenseSearchableModel::create(['title' => 'PHP Best Practices', 'body' => 'Advanced PHP']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('PHP')->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('title', 'PHP Programming'));
        $this->assertTrue($results->contains('title', 'PHP Best Practices'));
    }

    public function testSearchWithEmptyQueryReturnsAllDocuments(): void
    {
        TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')->get();

        $this->assertCount(2, $results);
    }

    public function testPaginateReturnsCorrectPage(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            TypesenseSearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $page1 = TypesenseSearchableModel::search('')->paginate(3, 'page', 1);
        $page2 = TypesenseSearchableModel::search('')->paginate(3, 'page', 2);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        $this->assertSame(10, $page1->total());
    }

    public function testFlushRemovesAllDocumentsFromCollection(): void
    {
        $models = new EloquentCollection([
            TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']),
            TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);

        // Verify documents exist
        $results = $this->typesense->collections[$models->first()->searchableAs()]->documents->search([
            'q' => '*',
            'query_by' => 'title',
        ]);
        $this->assertSame(2, $results['found']);

        // Flush
        $this->engine->flush($models->first());

        // Verify collection is deleted (Typesense flush deletes the collection)
        $this->expectException(\Typesense\Exceptions\ObjectNotFound::class);
        $this->typesense->collections[$models->first()->searchableAs()]->retrieve();
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            TypesenseSearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $builder = TypesenseSearchableModel::search('');
        $results = $this->engine->search($builder);

        $this->assertSame(5, $this->engine->getTotalCount($results));
    }

    public function testMapIdsReturnsCollectionOfIds(): void
    {
        $models = new EloquentCollection([
            TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']),
            TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);

        $builder = TypesenseSearchableModel::search('');
        $results = $this->engine->search($builder);
        $ids = $this->engine->mapIds($results);

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains((string) $models[0]->id));
        $this->assertTrue($ids->contains((string) $models[1]->id));
    }

    public function testKeysReturnsScoutKeys(): void
    {
        $models = new EloquentCollection([
            TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']),
            TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']),
        ]);

        $this->engine->update($models);

        $keys = TypesenseSearchableModel::search('')->keys();

        $this->assertCount(2, $keys);
    }
}
