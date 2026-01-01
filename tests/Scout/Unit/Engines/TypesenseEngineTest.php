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
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Document;
use Typesense\Documents;

/**
 * @internal
 * @coversNothing
 */
class TypesenseEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function createEngine(?MockInterface $client = null): TypesenseEngine
    {
        $client = $client ?? Mockery::mock(TypesenseClient::class);

        return new TypesenseEngine($client, 1000);
    }

    /**
     * Create a partial mock of the engine for testing methods that use getOrCreateCollectionFromModel.
     */
    protected function createPartialEngine(?MockInterface $client = null): MockInterface&TypesenseEngine
    {
        $client = $client ?? Mockery::mock(TypesenseClient::class);

        /** @var MockInterface&TypesenseEngine */
        return Mockery::mock(TypesenseEngine::class, [$client, 1000])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();
    }

    protected function createSearchableModelMock(): MockInterface
    {
        return Mockery::mock(Model::class . ', ' . SearchableInterface::class);
    }

    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $method = new ReflectionMethod($object, $methodName);

        return $method->invoke($object, ...$parameters);
    }

    public function testFiltersMethod(): void
    {
        $engine = $this->createEngine();

        $builder = Mockery::mock(Builder::class);
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
        $client = Mockery::mock(TypesenseClient::class);
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
        $document = Mockery::mock(Document::class);
        $document->shouldReceive('retrieve')->once()->andReturn([]);
        $document->shouldReceive('delete')->once()->andReturn([]);

        // Documents already implements ArrayAccess
        $documents = Mockery::mock(Documents::class);
        $documents->shouldReceive('offsetGet')
            ->with('123')
            ->andReturn($document);

        $collection = Mockery::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $engine = $this->createPartialEngine();
        $engine->shouldReceive('getOrCreateCollectionFromModel')
            ->once()
            ->andReturn($collection);

        $engine->delete(new EloquentCollection([$model]));
    }

    public function testDeleteWithEmptyCollectionDoesNothing(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');

        $engine = $this->createEngine($client);

        $engine->delete(new EloquentCollection([]));

        $this->assertTrue(true);
    }

    public function testFlushDeletesCollection(): void
    {
        $model = $this->createSearchableModelMock();

        $collection = Mockery::mock(TypesenseCollection::class);
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
        $collection = Mockery::mock(TypesenseCollection::class);
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

        $client = Mockery::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->andReturn($collections);

        $engine = $this->createEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['name' => 'test_index'], $result);
    }

    public function testGetTypesenseClientReturnsClient(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $engine = $this->createEngine($client);

        $this->assertSame($client, $engine->getTypesenseClient());
    }

    public function testMapReturnsEmptyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

        $builder = Mockery::mock(Builder::class);
        $results = ['found' => 0, 'hits' => []];

        $mapped = $engine->map($builder, $results, $model);

        $this->assertTrue($mapped->isEmpty());
    }

    public function testLazyMapReturnsLazyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection());

        $builder = Mockery::mock(Builder::class);
        $results = ['found' => 0, 'hits' => []];

        $lazyMapped = $engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(\Hypervel\Support\LazyCollection::class, $lazyMapped);
    }
}
