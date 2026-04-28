<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Hypervel\Context\RequestContext;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Http\Request;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Searchable;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\go;

class AlgoliaEngineTest extends TestCase
{
    public function testUpdateSavesObjects()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('saveObjects')
            ->once()
            ->with('users', [
                ['id' => 1, 'name' => 'Taylor', 'objectID' => 1],
            ]);

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'name' => 'Taylor']);
        $model->shouldReceive('scoutMetadata')->andReturn([]);
        $model->shouldReceive('getScoutKey')->andReturn(1);

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateEmptyCollectionDoesNothing()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client);
        $engine->update(new EloquentCollection);

        $this->assertTrue(true);
    }

    public function testUpdateFiltersOutEmptySearchableArrays()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');
        $model->shouldReceive('toSearchableArray')->andReturn([]);

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateIncludesScoutMetadata()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('saveObjects')
            ->once()
            ->with('users', [
                ['id' => 1, 'name' => 'Taylor', 'foo' => 'bar', 'objectID' => 1],
            ]);

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'name' => 'Taylor']);
        $model->shouldReceive('scoutMetadata')->andReturn(['foo' => 'bar']);
        $model->shouldReceive('getScoutKey')->andReturn(1);

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateWithSoftDeletesAddsSoftDeleteMetadata()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('saveObjects')
            ->once()
            ->with('users', m::on(function ($objects) {
                return isset($objects[0]['__soft_deleted']);
            }));

        $engine = new AlgoliaEngine($client, softDelete: true);

        $model = $this->createSoftDeleteSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'name' => 'Taylor']);
        $model->shouldReceive('scoutMetadata')->andReturn(['__soft_deleted' => 0]);
        $model->shouldReceive('getScoutKey')->andReturn(1);
        $model->shouldReceive('pushSoftDeleteMetadata')->once()->andReturnSelf();

        $engine->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesObjects()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('deleteObjects')
            ->once()
            ->with('users', [1]);

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');
        $model->shouldReceive('getScoutKey')->andReturn(1);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $engine->delete(new EloquentCollection([$model]));
    }

    public function testDeleteWithCustomScoutKey()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('deleteObjects')
            ->once()
            ->with('chirps', ['my-key']);

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('chirps');
        $model->shouldReceive('getScoutKey')->andReturn('my-key');
        $model->shouldReceive('getScoutKeyName')->andReturn('scout_id');

        $engine->delete(new EloquentCollection([$model]));
    }

    public function testDeleteWithRemoveableScoutCollection()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('deleteObjects')
            ->once()
            ->with('chirps', ['my-key']);

        $engine = new AlgoliaEngine($client);

        // RemoveableScoutCollection's delete path uses pluck($keyName) which
        // reads attributes directly via data_get. A Mockery mock can't satisfy
        // that because Model::__set routes through setAttribute (intercepted).
        // A real fixture model with setRawAttributes works cleanly — no DB
        // needed because delete() never hits the database.
        $model = new AlgoliaTestChirpModel;
        $model->setRawAttributes(['scout_id' => 'my-key']);

        $collection = new RemoveableScoutCollection([$model]);

        $engine->delete($collection);
    }

    public function testDeleteEmptyCollection()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldNotReceive('deleteObjects');

        $engine = new AlgoliaEngine($client);
        $engine->delete(new EloquentCollection);

        $this->assertTrue(true);
    }

    public function testDeleteIndex()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('deleteIndex')
            ->once()
            ->with('users')
            ->andReturn(['taskID' => 123]);

        $engine = new AlgoliaEngine($client);

        $result = $engine->deleteIndex('users');

        $this->assertEquals(['taskID' => 123], $result);
    }

    public function testFlush()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('clearObjects')
            ->once()
            ->with('users');

        $engine = new AlgoliaEngine($client);

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('users');

        $engine->flush($model);
    }

    public function testUpdateIndexSettings()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('setSettings')
            ->once()
            ->with('users', ['searchableAttributes' => ['name', 'email']]);

        $engine = new AlgoliaEngine($client);
        $engine->updateIndexSettings('users', ['searchableAttributes' => ['name', 'email']]);
    }

    public function testCreateIndexThrows()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $this->expectException(NotSupportedException::class);

        $engine->createIndex('users');
    }

    public function testSearchSendsCorrectParameters()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', ['query' => 'zonda', 'numericFilters' => ['foo=1']], []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->where('foo', 1);

        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersForWhereInSearch()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', ['query' => 'zonda', 'numericFilters' => ['foo=1', ['bar=1', 'bar=2']]], []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', [1, 2]);

        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersForEmptyWhereInSearch()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', ['query' => 'zonda', 'numericFilters' => ['foo=1', '0=1']], []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', []);

        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersForWhereNotInSearch()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', ['query' => 'zonda', 'numericFilters' => ['foo!=1', 'foo!=2']], []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->whereNotIn('foo', [1, 2]);

        $engine->search($builder);
    }

    public function testSearchIgnoresEmptyWhereNotIn()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', ['query' => 'zonda'], []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->whereNotIn('foo', []);

        $engine->search($builder);
    }

    public function testSearchSendsCorrectParametersForMixedSearch()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with(
                'users',
                ['query' => 'zonda', 'numericFilters' => ['foo=1', ['bar=1', 'bar=2'], 'baz!=1', 'baz!=2']],
                [],
            );

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'zonda');
        $builder->where('foo', 1)
            ->whereIn('bar', [1, 2])
            ->whereNotIn('baz', [1, 2]);

        $engine->search($builder);
    }

    public function testSearchWithCallbackUsesCallback()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldNotReceive('searchSingleIndex');

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $called = false;
        $builder = new Builder($model, 'zonda', function ($c, $query, $options) use ($client, &$called) {
            $called = true;
            $this->assertSame($client, $c);
            $this->assertSame('zonda', $query);

            return ['hits' => [], 'nbHits' => 0];
        });

        $result = $engine->search($builder);

        $this->assertTrue($called);
        $this->assertEquals(['hits' => [], 'nbHits' => 0], $result);
    }

    public function testPaginateComputesCorrectPage()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::on(function ($params) {
                return $params['hitsPerPage'] === 15 && $params['page'] === 1;
            }), []);

        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $engine->paginate($builder, 15, 2);
    }

    public function testMapReturnsEmptyWhenNoHits()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection);

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['hits' => []], $model);

        $this->assertCount(0, $results);
    }

    public function testMapPopulatesScoutMetadataFromUnderscoreKeys()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $searchableModel = m::mock(Model::class . ', ' . SearchableInterface::class);
        $searchableModel->shouldReceive('getScoutKey')->andReturn(1);
        $searchableModel->shouldReceive('withScoutMetadata')
            ->with('_rankingInfo', ['nbTypos' => 0])
            ->once()
            ->andReturnSelf();

        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn(new EloquentCollection([$searchableModel]));
        $model->shouldReceive('newCollection')
            ->andReturnUsing(fn ($models) => new EloquentCollection($models));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 1,
            'hits' => [
                ['objectID' => 1, 'id' => 1, '_rankingInfo' => ['nbTypos' => 0]],
            ],
        ], $model);

        $this->assertCount(1, $results);
    }

    public function testMapRespectsOrder()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $mockModels = [];
        foreach ([1, 2, 3, 4] as $id) {
            $mock = m::mock(Model::class . ', ' . SearchableInterface::class);
            $mock->shouldReceive('getScoutKey')->andReturn($id);
            $mockModels[] = $mock;
        }

        $models = new EloquentCollection($mockModels);

        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models);
        $model->shouldReceive('newCollection')
            ->andReturnUsing(fn ($models) => new EloquentCollection($models));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 4,
            'hits' => [
                ['objectID' => 1, 'id' => 1],
                ['objectID' => 2, 'id' => 2],
                ['objectID' => 4, 'id' => 4],
                ['objectID' => 3, 'id' => 3],
            ],
        ], $model);

        $this->assertCount(4, $results);
        $resultIds = $results->map(fn ($m) => $m->getScoutKey())->all();
        $this->assertEquals([1, 2, 4, 3], $resultIds);
    }

    public function testLazyMapRespectsOrder()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $mockModels = [];
        foreach ([1, 2, 3, 4] as $id) {
            $mock = m::mock(Model::class . ', ' . SearchableInterface::class);
            $mock->shouldReceive('getScoutKey')->andReturn($id);
            $mockModels[] = $mock;
        }

        $lazy = LazyCollection::make($mockModels);

        $queryBuilder = m::mock(\Hypervel\Database\Eloquent\Builder::class);
        $queryBuilder->shouldReceive('cursor')->andReturn($lazy);

        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('queryScoutModelsByIds')->andReturn($queryBuilder);

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'nbHits' => 4,
            'hits' => [
                ['objectID' => 1, 'id' => 1],
                ['objectID' => 2, 'id' => 2],
                ['objectID' => 4, 'id' => 4],
                ['objectID' => 3, 'id' => 3],
            ],
        ], $model);

        $this->assertInstanceOf(LazyCollection::class, $results);
        $resultIds = $results->map(fn ($m) => $m->getScoutKey())->all();
        $this->assertEquals([1, 2, 4, 3], $resultIds);
    }

    public function testGetTotalCount()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $this->assertEquals(42, $engine->getTotalCount(['nbHits' => 42]));
    }

    public function testMapIdsReturnsObjectIDs()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $results = $engine->mapIds([
            'nbHits' => 3,
            'hits' => [
                ['objectID' => 'a', 'id' => 1],
                ['objectID' => 'b', 'id' => 2],
                ['objectID' => 'c', 'id' => 3],
            ],
        ]);

        $this->assertEquals(['a', 'b', 'c'], $results->all());
    }

    public function testConfigureSoftDeleteFilter()
    {
        $client = m::mock(AlgoliaSearchClient::class);
        $engine = new AlgoliaEngine($client);

        $result = $engine->configureSoftDeleteFilter([
            'attributesForFaceting' => ['existing'],
        ]);

        $this->assertEquals(
            ['attributesForFaceting' => ['existing', 'filterOnly(__soft_deleted)']],
            $result,
        );
    }

    public function testIdentifyDisabledSendsEmptyRequestOptions()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::any(), []);

        $engine = new AlgoliaEngine($client, identify: false);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $engine->search($builder);
    }

    public function testIdentifyEnabledSendsHeadersFromRequest()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::any(), ['headers' => ['X-Forwarded-For' => '203.0.113.10']]);

        $engine = new AlgoliaEngine($client, identify: true);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        RequestContext::set($request);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $engine->search($builder);
    }

    public function testIdentifyEnabledWithAuthenticatedUserAddsUserToken()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::any(), m::on(function ($requestOptions) {
                return isset($requestOptions['headers']['X-Algolia-UserToken'])
                    && $requestOptions['headers']['X-Algolia-UserToken'] === '42';
            }));

        $engine = new AlgoliaEngine($client, identify: true);

        // Using a real fixture class (not a Mockery mock) because the engine
        // calls method_exists($user, 'getKey') — Mockery's __call-based method
        // stubs are not detected by method_exists, so a mock would be rejected.
        $user = new AlgoliaTestUser(42);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $request->setUserResolver(fn () => $user);
        RequestContext::set($request);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $engine->search($builder);
    }

    public function testIdentifyIgnoresPrivateIPs()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::any(), []);

        $engine = new AlgoliaEngine($client, identify: true);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        RequestContext::set($request);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $engine->search($builder);
    }

    public function testIdentifyHeadersReflectTheCurrentRequestNotTheCachedOne()
    {
        // Regression test for the deliberate divergence from Laravel's
        // EngineManager::defaultAlgoliaHeaders(). The engine is constructed
        // ONCE (as it would be in a Swoole worker) and must pick up the
        // current request's IP on every search — not the IP of whichever
        // request happened to trigger engine construction.
        $client = m::mock(AlgoliaSearchClient::class);

        $capturedHeaders = [];
        $client->shouldReceive('searchSingleIndex')
            ->twice()
            ->with('users', m::any(), m::on(function ($opts) use (&$capturedHeaders) {
                $capturedHeaders[] = $opts['headers']['X-Forwarded-For'] ?? null;

                return true;
            }));

        $engine = new AlgoliaEngine($client, identify: true);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        try {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));
            $engine->search($builder);
        } finally {
            RequestContext::forget();
        }

        try {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']));
            $engine->search($builder);
        } finally {
            RequestContext::forget();
        }

        $this->assertSame(['203.0.113.10', '198.51.100.20'], $capturedHeaders);
    }

    public function testIdentifyHeadersDoNotLeakAcrossCoroutines()
    {
        // Companion regression test: RequestContext is coroutine-local, so
        // two concurrent coroutines each seeding their own request must each
        // see their own headers in the resulting search call — never the
        // other coroutine's.
        $client = m::mock(AlgoliaSearchClient::class);

        $capturedHeaders = [];
        $client->shouldReceive('searchSingleIndex')
            ->twice()
            ->with('users', m::any(), m::on(function ($opts) use (&$capturedHeaders) {
                $capturedHeaders[] = $opts['headers']['X-Forwarded-For'] ?? null;

                return true;
            }));

        $engine = new AlgoliaEngine($client, identify: true);

        $model = m::mock(AlgoliaTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('users');

        $builder = new Builder($model, 'query');

        $waiter = new WaitGroup;
        $waiter->add(2);

        go(function () use ($engine, $builder, $waiter) {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']));
            $engine->search($builder);
            $waiter->done();
        });

        go(function () use ($engine, $builder, $waiter) {
            RequestContext::set(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']));
            $engine->search($builder);
            $waiter->done();
        });

        $waiter->wait();

        sort($capturedHeaders);
        $this->assertSame(['198.51.100.20', '203.0.113.10'], $capturedHeaders);
    }

    public function testUpdateSoftDeletedWithEmptySearchableArrayDoesNotSave()
    {
        // Covers the Laravel Feature-test gap (test_update_empty_searchable_
        // array_from_soft_deleted_model_does_not_add_objects_to_index). With
        // softDelete enabled and an empty toSearchableArray(), the engine
        // still calls pushSoftDeleteMetadata (runs before the empty check)
        // but does not call saveObjects.
        $client = m::mock(AlgoliaSearchClient::class);
        $client->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client, softDelete: true);

        $model = $this->createSoftDeleteSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('chirps');
        $model->shouldReceive('toSearchableArray')->andReturn([]);
        $model->shouldReceive('pushSoftDeleteMetadata')->once()->andReturnSelf();

        $engine->update(new EloquentCollection([$model]));
    }

    public function testCallForwardsUnknownMethodsToClient()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('customMethod')
            ->once()
            ->with('arg')
            ->andReturn('result');

        $engine = new AlgoliaEngine($client);

        $this->assertEquals('result', $engine->customMethod('arg'));
    }

    public function testDeleteAllIndexesWithPrefixScopesToPrefixedIndexes()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn([
                'items' => [
                    ['name' => 'test_users'],
                    ['name' => 'test_posts'],
                    ['name' => 'other_data'],
                ],
            ]);

        $client->shouldReceive('deleteIndex')->once()->with('test_users');
        $client->shouldReceive('deleteIndex')->once()->with('test_posts');
        $client->shouldNotReceive('deleteIndex')->with('other_data');

        $engine = new AlgoliaEngine($client);
        $engine->deleteAllIndexes('test_');
    }

    public function testDeleteAllIndexesWithNullPrefixDeletesEverything()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn([
                'items' => [
                    ['name' => 'test_users'],
                    ['name' => 'test_posts'],
                    ['name' => 'other_data'],
                ],
            ]);

        $client->shouldReceive('deleteIndex')->once()->with('test_users');
        $client->shouldReceive('deleteIndex')->once()->with('test_posts');
        $client->shouldReceive('deleteIndex')->once()->with('other_data');

        $engine = new AlgoliaEngine($client);
        $engine->deleteAllIndexes(null);
    }

    public function testDeleteAllIndexesWithEmptyStringPrefixDeletesEverything()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn([
                'items' => [
                    ['name' => 'test_users'],
                    ['name' => 'test_posts'],
                    ['name' => 'other_data'],
                ],
            ]);

        $client->shouldReceive('deleteIndex')->once()->with('test_users');
        $client->shouldReceive('deleteIndex')->once()->with('test_posts');
        $client->shouldReceive('deleteIndex')->once()->with('other_data');

        $engine = new AlgoliaEngine($client);
        $engine->deleteAllIndexes('');
    }

    public function testDeleteAllIndexesReturnsResponses()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn([
                'items' => [
                    ['name' => 'test_users'],
                    ['name' => 'test_posts'],
                ],
            ]);

        $client->shouldReceive('deleteIndex')->with('test_users')->andReturn(['taskID' => 1]);
        $client->shouldReceive('deleteIndex')->with('test_posts')->andReturn(['taskID' => 2]);

        $engine = new AlgoliaEngine($client);
        $responses = $engine->deleteAllIndexes('test_');

        $this->assertEquals([['taskID' => 1], ['taskID' => 2]], $responses);
    }

    public function testDeleteAllIndexesWithNoIndexesReturnsEmptyArray()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn(['items' => []]);

        $client->shouldNotReceive('deleteIndex');

        $engine = new AlgoliaEngine($client);
        $result = $engine->deleteAllIndexes('test_');

        $this->assertEquals([], $result);
    }

    public function testDeleteAllIndexesSkipsEntriesWithoutStringNames()
    {
        $client = m::mock(AlgoliaSearchClient::class);

        $client->shouldReceive('listIndices')
            ->once()
            ->andReturn([
                'items' => [
                    ['name' => 'test_users'],
                    ['name' => 42],          // non-string, should skip
                    ['no_name_field' => 'x'], // missing name, should skip
                    ['name' => 'test_posts'],
                ],
            ]);

        $client->shouldReceive('deleteIndex')->once()->with('test_users');
        $client->shouldReceive('deleteIndex')->once()->with('test_posts');
        $client->shouldNotReceive('deleteIndex')->with(42);

        $engine = new AlgoliaEngine($client);
        $responses = $engine->deleteAllIndexes('test_');

        $this->assertCount(2, $responses);
    }

    protected function createSearchableModelMock(): m\MockInterface
    {
        return m::mock(Model::class . ', ' . SearchableInterface::class);
    }

    protected function createSoftDeleteSearchableModelMock(): m\MockInterface
    {
        // Must mock a class that uses SoftDeletes for usesSoftDelete() to return true
        return m::mock(AlgoliaTestSoftDeleteModel::class . ', ' . SearchableInterface::class);
    }
}

/**
 * Test model for AlgoliaEngine tests.
 */
class AlgoliaTestSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected array $guarded = [];

    public bool $timestamps = false;
}

/**
 * Test model with soft deletes for AlgoliaEngine tests.
 */
class AlgoliaTestSoftDeleteModel extends Model implements SearchableInterface
{
    use Searchable;
    use SoftDeletes;

    protected array $guarded = [];

    public bool $timestamps = false;
}

/**
 * Test model with a custom scout key for AlgoliaEngine tests.
 *
 * Exercises the RemoveableScoutCollection delete path, which uses
 * pluck($scoutKeyName) — reading attribute values directly rather than
 * calling getScoutKey(). Needs a real Eloquent model so setRawAttributes
 * populates the attributes array for data_get to find.
 *
 * Deliberately does NOT use the Searchable trait: bootSearchable()
 * instantiates ModelObserver, which in its constructor reads from the
 * Config facade. Without a facade root (bare unit test), that throws.
 * The engine's delete() path only calls getScoutKeyName() and
 * indexableAs() at runtime; no SearchableInterface runtime check.
 */
class AlgoliaTestChirpModel extends Model
{
    protected ?string $table = 'chirps';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function getScoutKey(): mixed
    {
        return $this->getAttribute('scout_id');
    }

    public function getScoutKeyName(): string
    {
        return 'scout_id';
    }

    public function indexableAs(): string
    {
        return $this->getTable();
    }
}

/**
 * Minimal user stub for identifyHeaders() tests.
 *
 * Needs a real class (not a Mockery mock) because the engine uses
 * method_exists($user, 'getKey') to detect the presence of the method.
 * Mockery stubs methods via __call which are invisible to method_exists.
 */
class AlgoliaTestUser
{
    public function __construct(private mixed $key)
    {
    }

    public function getKey(): mixed
    {
        return $this->key;
    }
}
