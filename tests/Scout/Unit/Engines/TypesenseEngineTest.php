<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionMethod;
use Typesense\ApiCall;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseEngineTest extends TestCase
{
    protected function createEngine(?MockInterface $client = null): TypesenseEngine
    {
        $client = $client ?? m::mock(TypesenseClient::class);

        return new TypesenseEngine($client, 1000);
    }

    protected function createSearchableModelMock(): MockInterface
    {
        return m::mock(Model::class . ', ' . SearchableInterface::class);
    }

    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $method = new ReflectionMethod($object, $methodName);

        return $method->invoke($object, ...$parameters);
    }

    public function testFiltersMethod(): void
    {
        $engine = $this->createEngine();

        $builder = m::mock(Builder::class);
        $builder->wheres = [
            ['field' => 'status', 'operator' => '=', 'value' => 'active'],
            ['field' => 'age', 'operator' => '>=', 'value' => 25],
            ['field' => 'priority', 'operator' => '!=', 'value' => TypesenseIntegerPriority::Low],
        ];
        $builder->whereIns = [
            'category' => [TypesenseStringCategory::Electronics, TypesenseStringCategory::Books],
        ];
        $builder->whereNotIns = [
            'brand' => ['apple', 'samsung'],
        ];

        $result = $this->invokeMethod($engine, 'filters', [$builder]);

        $this->assertStringContainsString('status:=active', $result);
        $this->assertStringContainsString('age:>=25', $result);
        $this->assertStringContainsString('priority:!=2', $result);
        $this->assertStringContainsString('category:=[electronics, books]', $result);
        $this->assertStringContainsString('brand:!=[apple, samsung]', $result);
    }

    public function testParseFilterValueMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals('true', $this->invokeMethod($engine, 'parseFilterValue', [true]));
        $this->assertEquals('false', $this->invokeMethod($engine, 'parseFilterValue', [false]));
        $this->assertEquals(25, $this->invokeMethod($engine, 'parseFilterValue', [25]));
        $this->assertEquals(3.14, $this->invokeMethod($engine, 'parseFilterValue', [3.14]));
        $this->assertEquals('test', $this->invokeMethod($engine, 'parseFilterValue', ['test']));
        $this->assertEquals('books', $this->invokeMethod($engine, 'parseFilterValue', [TypesenseStringCategory::Books]));
        $this->assertEquals(1, $this->invokeMethod($engine, 'parseFilterValue', [TypesenseIntegerPriority::High]));
        $this->assertEquals(
            ['electronics', 2],
            $this->invokeMethod($engine, 'parseFilterValue', [[TypesenseStringCategory::Electronics, TypesenseIntegerPriority::Low]])
        );
    }

    public function testParseWhereFilterMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals('status:=active', $this->invokeMethod($engine, 'parseWhereFilter', ['active', 'status']));
        $this->assertEquals('age:=25', $this->invokeMethod($engine, 'parseWhereFilter', [25, 'age']));
        $this->assertEquals('age:>=25', $this->invokeMethod($engine, 'parseWhereFilter', [25, 'age', '>=']));
        $this->assertEquals('status:!=inactive', $this->invokeMethod($engine, 'parseWhereFilter', ['inactive', 'status', '!=']));
    }

    public function testParseWhereFilterRejectsUnsupportedOperators(): void
    {
        $engine = $this->createEngine();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Typesense filter operator [LIKE].');

        $this->invokeMethod($engine, 'parseWhereFilter', ['active', 'status', 'LIKE']);
    }

    public function testParseWhereInFilterMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals(
            'category:=[electronics, books]',
            $this->invokeMethod($engine, 'parseWhereInFilter', [['electronics', 'books'], 'category'])
        );
    }

    public function testParseWhereNotInFilterMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals(
            'brand:!=[apple, samsung]',
            $this->invokeMethod($engine, 'parseWhereNotInFilter', [['apple', 'samsung'], 'brand'])
        );
    }

    public function testParseOrderByMethod(): void
    {
        $engine = $this->createEngine();

        $orders = [
            ['column' => 'name', 'direction' => 'asc'],
            ['column' => 'created_at', 'direction' => 'desc'],
        ];

        $result = $this->invokeMethod($engine, 'parseOrderBy', [$orders]);

        $this->assertEquals('name:asc,created_at:desc', $result);
    }

    public function testMapIdsMethod(): void
    {
        $engine = $this->createEngine();

        $results = [
            'hits' => [
                ['document' => ['id' => 1]],
                ['document' => ['id' => 2]],
                ['document' => ['id' => 3]],
            ],
        ];

        $ids = $engine->mapIds($results);

        $this->assertEquals([1, 2, 3], $ids->all());
    }

    public function testMapIdsReturnsEmptyCollectionForNoHits(): void
    {
        $engine = $this->createEngine();

        $results = ['hits' => []];

        $ids = $engine->mapIds($results);

        $this->assertTrue($ids->isEmpty());
    }

    public function testGetTotalCountMethod(): void
    {
        $engine = $this->createEngine();

        $resultsWithFound = ['found' => 5];
        $resultsWithoutFound = ['hits' => []];

        $this->assertEquals(5, $engine->getTotalCount($resultsWithFound));
        $this->assertEquals(0, $engine->getTotalCount($resultsWithoutFound));
    }

    public function testCreateIndexThrowsNotSupportedException(): void
    {
        $engine = $this->createEngine();

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('Typesense indexes are created automatically upon adding objects.');

        $engine->createIndex('test_index');
    }

    public function testUpdateWithEmptyCollectionDoesNothing(): void
    {
        $client = m::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');

        $engine = $this->createEngine($client);

        $engine->update(new EloquentCollection([]));

        $this->assertTrue(true); // No exception means success
    }

    public function testUpdateTransformsBeforeTouchingTypesense(): void
    {
        $client = m::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');

        $model = new TypesenseLifecycleModel;
        $model->searchableData = [];

        $this->createEngine($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateImportsIntoIndexableCollection(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldNotReceive('create');
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->with([['id' => 1, 'title' => 'Scout']], ['action' => 'upsert'])
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateCreatesMissingCollectionAndRetriesImportOnce(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->twice()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldReceive('create')
            ->once()
            ->with([
                'fields' => [['name' => 'title', 'type' => 'string']],
                'name' => 'write_index',
            ])
            ->andReturn(['name' => 'write_index']);
        $collection->shouldReceive('getDocuments')->twice()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->ordered()
            ->andThrow(new ObjectNotFound('Collection not found'));
        $documents->shouldReceive('import')
            ->once()
            ->ordered()
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateAcceptsOnlyCollectionAlreadyExistsCreateRace(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->twice()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldReceive('create')->once()->andThrow(new ObjectAlreadyExists('Collection exists'));
        $collection->shouldReceive('getDocuments')->twice()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->ordered()
            ->andThrow(new ObjectNotFound('Collection not found'));
        $documents->shouldReceive('import')
            ->once()
            ->ordered()
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdatePropagatesOtherCollectionCreateFailures(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $exception = new TypesenseClientError('Invalid schema');

        $client->shouldReceive('getCollections')->twice()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldReceive('create')->once()->andThrow($exception);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')->once()->andThrow(new ObjectNotFound('Collection not found'));

        $model = new TypesenseLifecycleModel;

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdatePropagatesNonMissingImportFailure(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $exception = new TypesenseClientError('Authentication failed');

        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldNotReceive('create');
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')->once()->andThrow($exception);

        $model = new TypesenseLifecycleModel;

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesDocumentsFromIndex(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('write_index');
        $model->shouldReceive('getScoutKey')->andReturn(123);

        $document = m::mock(Document::class);
        $document->shouldReceive('delete')->once()->andReturn([]);

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $this->createEngine($client)->delete(new EloquentCollection([$model]));
    }

    public function testDeleteUsesStoredScoutKeyFromRemoveableCollection(): void
    {
        $document = m::mock(Document::class);
        $document->shouldReceive('delete')->once()->andReturn([]);

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')->with('stored-scout-key')->once()->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $model = new TypesenseLifecycleModel;
        $model->setRawAttributes(['scout_id' => 'stored-scout-key']);

        $this->createEngine($client)->delete(new RemoveableScoutCollection([$model]));
    }

    public function testDeleteWithEmptyCollectionDoesNothing(): void
    {
        $client = m::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');

        $engine = $this->createEngine($client);

        $engine->delete(new EloquentCollection([]));

        $this->assertTrue(true);
    }

    public function testDeleteDocumentReturnsEmptyArrayWhenDocumentNotFound(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('getScoutKey')->andReturn(123);

        $document = m::mock(Document::class);
        $document->shouldReceive('delete')->once()->andThrow(new ObjectNotFound('Document not found'));

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $model->shouldReceive('indexableAs')->andReturn('write_index');

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $this->createEngine($client)->delete(new EloquentCollection([$model]));

        $this->assertTrue(true);
    }

    public function testDeleteDocumentThrowsOnNonNotFoundErrors(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('getScoutKey')->andReturn(123);

        $document = m::mock(Document::class);
        $document->shouldReceive('delete')->once()->andThrow(new TypesenseClientError('Connection failed'));

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $model->shouldReceive('indexableAs')->andReturn('write_index');

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $this->expectException(TypesenseClientError::class);
        $this->expectExceptionMessage('Connection failed');

        $this->createEngine($client)->delete(new EloquentCollection([$model]));
    }

    public function testFlushDeletesCollection(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('write_index');

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('delete')->once();

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $this->createEngine($client)->flush($model);
    }

    public function testFlushTreatsMissingCollectionAsAlreadyFlushed(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('write_index');

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('delete')->once()->andThrow(new ObjectNotFound('Collection not found'));

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collections->shouldNotReceive('create');

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $this->createEngine($client)->flush($model);
    }

    public function testDeleteIndexCallsTypesenseDelete(): void
    {
        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('delete')
            ->once()
            ->andReturn(['name' => 'test_index']);

        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('test_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('test_index')->once();

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $engine = $this->createEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['name' => 'test_index'], $result);
    }

    public function testCollectionHandlesAreDetachedAndMagicNamesRemainUsable(): void
    {
        $collections = new Collections(m::mock(ApiCall::class));

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->twice()->andReturn($collections);

        $engine = $this->createEngine($client);

        $first = $this->invokeMethod($engine, 'collection', ['first']);
        $magicName = $this->invokeMethod($engine, 'collection', ['typesenseCollections']);

        $this->assertInstanceOf(TypesenseCollection::class, $first);
        $this->assertInstanceOf(TypesenseCollection::class, $magicName);
        $this->assertFalse(isset($collections['first']));
        $this->assertFalse(isset($collections['typesenseCollections']));
        $this->assertInstanceOf(Documents::class, $magicName->getDocuments());
    }

    public function testGetTypesenseClientReturnsClient(): void
    {
        $client = m::mock(TypesenseClient::class);
        $engine = $this->createEngine($client);

        $this->assertSame($client, $engine->getTypesenseClient());
    }

    public function testSearchUsesExplicitWithinCollection(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('custom_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('custom_index')->once();
        $collections->shouldNotReceive('create');
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('search')
            ->once()
            ->with(m::on(fn (array $parameters): bool => $parameters['q'] === 'scout'))
            ->andReturn(['found' => 0, 'hits' => []]);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, 'scout'))->within('custom_index');

        $this->assertSame(
            ['found' => 0, 'hits' => []],
            $this->createPartialEngineWithConfig($client)->search($builder),
        );
    }

    public function testSearchCreatesMissingSearchableCollectionAndRetriesOnce(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->twice()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('read_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('read_index')->once();
        $collections->shouldReceive('create')
            ->once()
            ->with([
                'fields' => [['name' => 'title', 'type' => 'string']],
                'name' => 'read_index',
            ])
            ->andReturn(['name' => 'read_index']);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('search')
            ->once()
            ->ordered()
            ->andThrow(new ObjectNotFound('Collection not found'));
        $documents->shouldReceive('search')
            ->once()
            ->ordered()
            ->andReturn(['found' => 0, 'hits' => []]);

        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, 'scout');

        $this->createPartialEngineWithConfig($client)->search($builder);
    }

    public function testSearchPropagatesNonMissingFailure(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $exception = new TypesenseClientError('Authentication failed');

        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('read_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('read_index')->once();
        $collections->shouldNotReceive('create');
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('search')->once()->andThrow($exception);

        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, 'scout');

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->search($builder);
    }

    public function testMapReturnsEmptyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection);

        $builder = m::mock(Builder::class);
        $results = ['found' => 0, 'hits' => []];

        $mapped = $engine->map($builder, $results, $model);

        $this->assertTrue($mapped->isEmpty());
    }

    public function testLazyMapReturnsLazyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection);

        $builder = m::mock(Builder::class);
        $results = ['found' => 0, 'hits' => []];

        $lazyMapped = $engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(\Hypervel\Support\LazyCollection::class, $lazyMapped);
    }

    public function testBuildSearchParametersIncludesBasicParameters(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'search term';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [];
        $builder->options = [];

        $params = $engine->buildSearchParameters($builder, 1, 25);

        $this->assertSame('search term', $params['q']);
        $this->assertSame(1, $params['page']);
        $this->assertSame(25, $params['per_page']);
        $this->assertArrayHasKey('query_by', $params);
        $this->assertArrayHasKey('filter_by', $params);
        $this->assertArrayHasKey('highlight_start_tag', $params);
        $this->assertArrayHasKey('highlight_end_tag', $params);
    }

    public function testBuildSearchParametersIncludesFilters(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'test';
        $builder->wheres = [
            ['field' => 'status', 'operator' => '=', 'value' => 'active'],
        ];
        $builder->whereIns = ['category' => ['a', 'b']];
        $builder->whereNotIns = ['brand' => ['x']];
        $builder->orders = [];
        $builder->options = [];

        $params = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertStringContainsString('status:=active', $params['filter_by']);
        $this->assertStringContainsString('category:=[a, b]', $params['filter_by']);
        $this->assertStringContainsString('brand:!=[x]', $params['filter_by']);
    }

    public function testBuildSearchParametersMergesBuilderOptions(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'test';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [];
        $builder->options = [
            'exhaustive_search' => true,
            'custom_param' => 'value',
        ];

        $params = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertTrue($params['exhaustive_search']);
        $this->assertSame('value', $params['custom_param']);
    }

    public function testBuildSearchParametersIncludesSortBy(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'test';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [
            ['column' => 'name', 'direction' => 'asc'],
            ['column' => 'created_at', 'direction' => 'desc'],
        ];
        $builder->options = [];

        $params = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('name:asc,created_at:desc', $params['sort_by']);
    }

    public function testBuildSearchParametersAppendsToExistingSortBy(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'test';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [
            ['column' => 'name', 'direction' => 'asc'],
        ];
        $builder->options = [
            'sort_by' => '_text_match:desc',
        ];

        $params = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('_text_match:desc,name:asc', $params['sort_by']);
    }

    public function testBuildSearchParametersWithDifferentPageAndPerPage(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = 'query';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [];
        $builder->options = [];

        $params = $engine->buildSearchParameters($builder, 5, 50);

        $this->assertSame(5, $params['page']);
        $this->assertSame(50, $params['per_page']);
    }

    public function testBuildSearchParametersWithEmptyQuery(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelMock();

        $builder = m::mock(Builder::class);
        $builder->model = $model;
        $builder->query = '';
        $builder->wheres = [];
        $builder->whereIns = [];
        $builder->whereNotIns = [];
        $builder->orders = [];
        $builder->options = [];

        $params = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('', $params['q']);
        $this->assertSame('', $params['filter_by']);
    }

    /**
     * Create a partial engine mock that stubs getConfig to avoid container dependency.
     */
    protected function createPartialEngineWithConfig(?MockInterface $client = null): MockInterface&TypesenseEngine
    {
        $client = $client ?? m::mock(TypesenseClient::class);

        /** @var MockInterface&TypesenseEngine */
        $engine = m::mock(TypesenseEngine::class, [$client, 1000])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $engine->shouldReceive('getConfig')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                // Return empty array for model-settings (no custom search params)
                if (str_starts_with($key, 'typesense.model-settings.')) {
                    return $default;
                }

                return $default;
            });

        return $engine;
    }
}

class TypesenseLifecycleModel extends Model
{
    /** @var array<string, mixed> */
    public array $searchableData = ['id' => 1, 'title' => 'Scout'];

    public function toSearchableArray(): array
    {
        return $this->searchableData;
    }

    public function scoutMetadata(): array
    {
        return [];
    }

    public function searchableAs(): string
    {
        return 'read_index';
    }

    public function indexableAs(): string
    {
        return 'write_index';
    }

    public function getScoutKey(): mixed
    {
        return $this->getAttribute($this->getScoutKeyName());
    }

    public function getScoutKeyName(): string
    {
        return 'scout_id';
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => 'stale_index',
            'fields' => [['name' => 'title', 'type' => 'string']],
        ];
    }
}

enum TypesenseStringCategory: string
{
    case Electronics = 'electronics';
    case Books = 'books';
}

enum TypesenseIntegerPriority: int
{
    case High = 1;
    case Low = 2;
}
