<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Searchable;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class MeilisearchEngineTest extends TestCase
{
    public function testUpdateAddsDocumentsToIndex()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('addDocuments')
            ->once()
            ->with([
                ['id' => 1, 'title' => 'Test'],
            ], 'id');

        $engine = new MeilisearchEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('test_index');
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'title' => 'Test']);
        $model->shouldReceive('scoutMetadata')->andReturn([]);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutKey')->andReturn(1);

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateEmptyCollectionDoesNothing()
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');

        $engine = new MeilisearchEngine($client);
        $engine->update(new EloquentCollection());

        $this->assertTrue(true);
    }

    public function testUpdateWithSoftDeletesAddsSoftDeleteMetadata()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('addDocuments')
            ->once()
            ->with(m::on(function ($documents) {
                return isset($documents[0]['__soft_deleted']);
            }), 'id');

        $engine = new MeilisearchEngine($client, softDelete: true);

        $model = $this->createSoftDeleteSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('test_index');
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'title' => 'Test']);
        $model->shouldReceive('scoutMetadata')->andReturn(['__soft_deleted' => 0]);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutKey')->andReturn(1);
        $model->shouldReceive('pushSoftDeleteMetadata')->once()->andReturnSelf();

        $engine->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesDocumentsFromIndex()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('deleteDocuments')
            ->once()
            ->with([1, 2]);

        $engine = new MeilisearchEngine($client);

        $model1 = $this->createSearchableModelMock();
        $model1->shouldReceive('indexableAs')->andReturn('test_index');
        $model1->shouldReceive('getScoutKey')->andReturn(1);

        $model2 = $this->createSearchableModelMock();
        $model2->shouldReceive('getScoutKey')->andReturn(2);

        $engine->delete(new EloquentCollection([$model1, $model2]));
    }

    public function testDeleteEmptyCollectionDoesNothing()
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');

        $engine = new MeilisearchEngine($client);
        $engine->delete(new EloquentCollection());

        $this->assertTrue(true);
    }

    public function testSearchPerformsSearchOnMeilisearch()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('rawSearch')
            ->once()
            ->with('test query', m::any())
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'test query');

        $result = $engine->search($builder);

        $this->assertEquals(['hits' => [], 'totalHits' => 0], $result);
    }

    public function testSearchWithFilters()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('rawSearch')
            ->once()
            ->with('query', m::on(function ($params) {
                return str_contains($params['filter'], 'status="active"');
            }))
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'query');
        $builder->where('status', 'active');

        $engine->search($builder);
    }

    public function testPaginatePerformsPaginatedSearch()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('rawSearch')
            ->once()
            ->with('query', m::on(function ($params) {
                return $params['hitsPerPage'] === 15 && $params['page'] === 2;
            }))
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'query');

        $engine->paginate($builder, 15, 2);
    }

    public function testMapIdsReturnsEmptyCollectionIfNoHits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 0,
            'hits' => [],
        ], 'id');

        $this->assertCount(0, $results);
    }

    public function testMapIdsReturnsCorrectPrimaryKeys()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 4,
            'hits' => [
                ['id' => 1, 'title' => 'Test 1'],
                ['id' => 2, 'title' => 'Test 2'],
                ['id' => 3, 'title' => 'Test 3'],
                ['id' => 4, 'title' => 'Test 4'],
            ],
        ], 'id');

        $this->assertEquals([1, 2, 3, 4], $results->all());
    }

    public function testMapCorrectlyMapsResultsToModels()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        // Create a mock searchable model that tracks scout metadata
        $searchableModel = m::mock(Model::class);
        $searchableModel->shouldReceive('getScoutKey')->andReturn(1);
        $searchableModel->shouldReceive('withScoutMetadata')
            ->with('_rankingScore', 0.86)
            ->once()
            ->andReturnSelf();

        $model = m::mock(Model::class);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutModelsByIds')->andReturn(new EloquentCollection([$searchableModel]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ], $model);

        $this->assertCount(1, $results);
    }

    public function testMapReturnsEmptyCollectionWhenNoHits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['hits' => []], $model);

        $this->assertCount(0, $results);
    }

    public function testMapRespectsOrder()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        // Create mock models
        $mockModels = [];
        foreach ([1, 2, 3, 4] as $id) {
            $mock = m::mock(Model::class)->makePartial();
            $mock->shouldReceive('getScoutKey')->andReturn($id);
            $mockModels[] = $mock;
        }

        $models = new EloquentCollection($mockModels);

        $model = m::mock(Model::class);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models);

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'totalHits' => 4,
            'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ], $model);

        $this->assertCount(4, $results);
        // Check order is respected: 1, 2, 4, 3
        $resultIds = $results->map(fn ($m) => $m->getScoutKey())->all();
        $this->assertEquals([1, 2, 4, 3], $resultIds);
    }

    public function testLazyMapReturnsEmptyCollectionWhenNoHits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['hits' => []], $model);

        $this->assertInstanceOf(LazyCollection::class, $results);
        $this->assertCount(0, $results);
    }

    public function testGetTotalCountReturnsTotalHits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(3, $engine->getTotalCount(['totalHits' => 3]));
    }

    public function testGetTotalCountReturnsEstimatedTotalHits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(5, $engine->getTotalCount(['estimatedTotalHits' => 5]));
    }

    public function testGetTotalCountReturnsZeroWhenNoCountAvailable()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(0, $engine->getTotalCount([]));
    }

    public function testFlushDeletesAllDocuments()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('deleteAllDocuments')->once();

        $engine = new MeilisearchEngine($client);

        $model = m::mock(Model::class);
        $model->shouldReceive('indexableAs')->andReturn('test_index');

        $engine->flush($model);
    }

    public function testCreateIndexCreatesNewIndex()
    {
        $client = m::mock(Client::class);

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andThrow(new \Meilisearch\Exceptions\ApiException(
                new \GuzzleHttp\Psr7\Response(404),
                ['message' => 'Index not found']
            ));

        $taskInfo = ['taskUid' => 1, 'indexUid' => 'test_index', 'status' => 'enqueued'];
        $client->shouldReceive('createIndex')
            ->with('test_index', ['primaryKey' => 'id'])
            ->once()
            ->andReturn($taskInfo);

        $engine = new MeilisearchEngine($client);

        $result = $engine->createIndex('test_index', ['primaryKey' => 'id']);

        $this->assertSame($taskInfo, $result);
    }

    public function testCreateIndexReturnsExistingIndex()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $index->shouldReceive('getUid')->andReturn('test_index');

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $client->shouldNotReceive('createIndex');

        $engine = new MeilisearchEngine($client);

        $result = $engine->createIndex('test_index');

        $this->assertSame($index, $result);
    }

    public function testDeleteIndexDeletesIndex()
    {
        $client = m::mock(Client::class);

        $client->shouldReceive('deleteIndex')
            ->with('test_index')
            ->once()
            ->andReturn(['taskUid' => 1]);

        $engine = new MeilisearchEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['taskUid' => 1], $result);
    }

    public function testUpdateIndexSettingsWithEmbedders()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('updateSettings')
            ->with(['searchableAttributes' => ['title']])
            ->once();

        $index->shouldReceive('updateEmbedders')
            ->with(['default' => ['source' => 'openAi']])
            ->once();

        $engine = new MeilisearchEngine($client);
        $engine->updateIndexSettings('test_index', [
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => ['source' => 'openAi']],
        ]);

        $this->assertTrue(true);
    }

    public function testUpdateIndexSettingsWithoutEmbedders()
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('updateSettings')
            ->with(['searchableAttributes' => ['title', 'body']])
            ->once();

        $index->shouldNotReceive('updateEmbedders');

        $engine = new MeilisearchEngine($client);
        $engine->updateIndexSettings('test_index', [
            'searchableAttributes' => ['title', 'body'],
        ]);

        $this->assertTrue(true);
    }

    public function testConfigureSoftDeleteFilterAddsFilterableAttribute()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $settings = $engine->configureSoftDeleteFilter([
            'filterableAttributes' => ['status'],
        ]);

        $this->assertContains('__soft_deleted', $settings['filterableAttributes']);
        $this->assertContains('status', $settings['filterableAttributes']);
    }

    public function testEngineForwardsCallsToMeilisearchClient()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('health')
            ->once()
            ->andReturn(['status' => 'available']);

        $engine = new MeilisearchEngine($client);

        $result = $engine->health();

        $this->assertEquals(['status' => 'available'], $result);
    }

    public function testGetMeilisearchClientReturnsClient()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame($client, $engine->getMeilisearchClient());
    }

    protected function createSearchableModelMock(): m\MockInterface
    {
        $mock = m::mock(Model::class);

        return $mock;
    }

    protected function createSoftDeleteSearchableModelMock(): m\MockInterface
    {
        // Must mock a class that uses SoftDeletes for usesSoftDelete() to return true
        return m::mock(MeilisearchTestSoftDeleteModel::class);
    }
}

/**
 * Test model with soft deletes for MeilisearchEngine tests.
 */
class MeilisearchTestSoftDeleteModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected array $guarded = [];

    public bool $timestamps = false;
}
