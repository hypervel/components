<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use ReflectionMethod;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Document;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

/**
 * @internal
 * @coversNothing
 */
class TypesenseEngineTest extends TestCase
{
    protected function createEngine(?MockInterface $client = null): TypesenseEngine
    {
        $client = $client ?? m::mock(TypesenseClient::class);

        return new TypesenseEngine($client, 1000);
    }

    /**
     * Create a partial mock of the engine for testing methods that use getOrCreateCollectionFromModel.
     */
    protected function createPartialEngine(?MockInterface $client = null): MockInterface&TypesenseEngine
    {
        $client = $client ?? m::mock(TypesenseClient::class);

        /** @var MockInterface&TypesenseEngine */
        return m::mock(TypesenseEngine::class, [$client, 1000])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();
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
            'status' => 'active',
            'age' => 25,
        ];
        $builder->whereIns = [
            'category' => ['electronics', 'books'],
        ];
        $builder->whereNotIns = [
            'brand' => ['apple', 'samsung'],
        ];

        $result = $this->invokeMethod($engine, 'filters', [$builder]);

        $this->assertStringContainsString('status:=active', $result);
        $this->assertStringContainsString('age:=25', $result);
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
    }

    public function testParseWhereFilterMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals('status:=active', $this->invokeMethod($engine, 'parseWhereFilter', ['active', 'status']));
        $this->assertEquals('age:=25', $this->invokeMethod($engine, 'parseWhereFilter', [25, 'age']));
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

    public function testDeleteRemovesDocumentsFromIndex(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('getScoutKey')->andReturn(123);

        // Mock the Document object that's returned by array access on Documents
        $document = m::mock(Document::class);
        $document->shouldReceive('retrieve')->once()->andReturn([]);
        $document->shouldReceive('delete')->once()->andReturn([]);

        // Documents already implements ArrayAccess
        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $engine = $this->createPartialEngine();
        $engine->shouldReceive('getOrCreateCollectionFromModel')
            ->once()
            ->with($model, null, false) // Verify indexOperation=false to prevent collection creation
            ->andReturn($collection);

        $engine->delete(new EloquentCollection([$model]));
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

        // Mock the Document object to throw ObjectNotFound on retrieve
        $document = m::mock(Document::class);
        $document->shouldReceive('retrieve')->once()->andThrow(new ObjectNotFound('Document not found'));
        $document->shouldNotReceive('delete');

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $engine = $this->createPartialEngine();
        $engine->shouldReceive('getOrCreateCollectionFromModel')
            ->once()
            ->with($model, null, false)
            ->andReturn($collection);

        // Should not throw - idempotent delete
        $engine->delete(new EloquentCollection([$model]));

        $this->assertTrue(true);
    }

    public function testDeleteDocumentThrowsOnNonNotFoundErrors(): void
    {
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('getScoutKey')->andReturn(123);

        // Mock the Document object to throw TypesenseClientError (network/auth error)
        $document = m::mock(Document::class);
        $document->shouldReceive('retrieve')->once()->andThrow(new TypesenseClientError('Connection failed'));

        $documents = m::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $engine = $this->createPartialEngine();
        $engine->shouldReceive('getOrCreateCollectionFromModel')
            ->once()
            ->with($model, null, false)
            ->andReturn($collection);

        $this->expectException(TypesenseClientError::class);
        $this->expectExceptionMessage('Connection failed');

        $engine->delete(new EloquentCollection([$model]));
    }

    public function testFlushDeletesCollection(): void
    {
        $model = $this->createSearchableModelMock();

        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('delete')->once();

        $engine = $this->createPartialEngine();
        $engine->shouldReceive('getOrCreateCollectionFromModel')
            ->once()
            ->with($model)
            ->andReturn($collection);

        $engine->flush($model);
    }

    public function testDeleteIndexCallsTypesenseDelete(): void
    {
        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('delete')
            ->once()
            ->andReturn(['name' => 'test_index']);

        // Create a test double that extends Collections to satisfy return type
        $collections = new class($collection) extends \Typesense\Collections {
            private $mockCollection;

            public function __construct($mockCollection)
            {
                // Don't call parent constructor - we're mocking
                $this->mockCollection = $mockCollection;
            }

            public function __get($name)
            {
                return $this->mockCollection;
            }
        };

        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->andReturn($collections);

        $engine = $this->createEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['name' => 'test_index'], $result);
    }

    public function testGetTypesenseClientReturnsClient(): void
    {
        $client = m::mock(TypesenseClient::class);
        $engine = $this->createEngine($client);

        $this->assertSame($client, $engine->getTypesenseClient());
    }

    public function testMapReturnsEmptyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

        $builder = m::mock(Builder::class);
        $results = ['found' => 0, 'hits' => []];

        $mapped = $engine->map($builder, $results, $model);

        $this->assertTrue($mapped->isEmpty());
    }

    public function testLazyMapReturnsLazyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

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
        $builder->wheres = ['status' => 'active'];
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
     * Create a partial engine mock that stubs getConfig to avoid ApplicationContext dependency.
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
