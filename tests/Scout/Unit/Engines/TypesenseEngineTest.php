<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithNativeEmbedding;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithPrecomputedEmbedding;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Throwable;
use Typesense\ApiCall;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\RequestMalformed;
use Typesense\Exceptions\TypesenseClientError;
use Typesense\MultiSearch;

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
        $this->expectExceptionMessageIs('Unsupported Typesense filter operator [LIKE].');

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
        $this->expectExceptionMessageIs('Typesense indexes are created automatically upon adding objects.');

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

    public function testUpdateMethodWithEmplaceAction(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->with([['id' => 1, 'title' => 'Scout']], ['action' => 'emplace'])
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $engine = $this->createPartialEngineWithConfig($client, config: ['typesense.import_action' => 'emplace']);

        $engine->update(new EloquentCollection([new TypesenseLifecycleModel]));
    }

    public function testUpdatePreparesTheFinalSearchableDocument(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->with([['id' => 1, 'title' => 'Scout', 'tenant_id' => 42]], ['action' => 'upsert'])
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;
        $engine = $this->createPartialEngineWithConfig($client);
        Scout::prepareSearchableDocumentUsing(function (
            array $document,
            Model $givenModel,
            Engine $givenEngine
        ) use ($model, $engine): array {
            $this->assertSame(['id' => 1, 'title' => 'Scout'], $document);
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);

            return [...$document, 'tenant_id' => 42];
        });

        $engine->update(new EloquentCollection([$model]));
    }

    public function testUpdateAddsPrecomputedEmbeddingsToDocumentsBeforePreparingThem(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('table')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('table')->once();
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->with([
                ['id' => 10, 'name' => 'First', 'embedding' => [0.1, 0.2], 'embedding_vector' => [0.1, 0.2]],
                ['id' => 20, 'name' => 'Second', 'embedding' => [0.3, 0.4], 'embedding_vector' => [0.3, 0.4]],
            ], ['action' => 'upsert'])
            ->andReturn([['success' => true], ['success' => true]]);

        $engine = $this->createSemanticEngine(['attribute' => 'embedding_vector', 'dimensions' => 2], client: $client);

        $first = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'First']);
        $first->setAttribute('embedding', [0.1, 0.2]);

        $second = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Second']);
        $second->setAttribute('embedding', [0.3, 0.4]);

        $preparedVectors = [];

        Scout::prepareSearchableDocumentUsing(function (array $document) use (&$preparedVectors): array {
            $preparedVectors[] = $document['embedding_vector'];

            return $document;
        });

        $engine->update($first->newCollection([$first, $second]));

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $preparedVectors);
    }

    public function testUpdateDoesNotGenerateEmbeddingsWhenUsingNativeEmbeddings(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('table')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('table')->once();
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldReceive('import')
            ->once()
            ->with([['id' => 1, 'name' => 'Model 1']], ['action' => 'upsert'])
            ->andReturn([['success' => true]]);

        $engine = $this->createSemanticEngine(
            ['attribute' => 'embedding', 'driver' => 'typesense'],
            client: $client,
            model: SearchableModelWithNativeEmbedding::class,
        );

        $model = new SearchableModelWithNativeEmbedding(['id' => 1, 'name' => 'Model 1']);

        $engine->update($model->newCollection([$model]));
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

    public function testMissingCollectionPreparesSettingsBeforeTheAuthoritativeName(): void
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
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'tenant_id', 'type' => 'int64'],
                ],
                'name' => 'write_index',
            ]);
        $collection->shouldReceive('getDocuments')->twice()->andReturn($documents);
        $documents->shouldReceive('import')->once()->ordered()->andThrow(new ObjectNotFound('Collection not found'));
        $documents->shouldReceive('import')
            ->once()
            ->ordered()
            ->andReturn([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;
        $engine = $this->createPartialEngineWithConfig($client);
        Scout::prepareIndexSettingsUsing(function (
            array $settings,
            ?Model $givenModel,
            Engine $givenEngine,
            string $index
        ) use ($model, $engine): array {
            $this->assertSame([
                'fields' => [['name' => 'title', 'type' => 'string']],
            ], $settings);
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);
            $this->assertSame('write_index', $index);

            return [
                ...$settings,
                'fields' => [...$settings['fields'], ['name' => 'tenant_id', 'type' => 'int64']],
                'name' => 'callback_attempted_override',
            ];
        });

        $engine->update(new EloquentCollection([$model]));
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

        $model = $this->createSearchableModelMock();
        $model->shouldReceive('getScoutKeyName')->andReturn('scout_id');
        $model->shouldReceive('getAttribute')->with('scout_id')->andReturn('stored-scout-key');
        $model->shouldReceive('indexableAs')->andReturn('write_index');

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
        $this->expectExceptionMessageIs('Connection failed');

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

    public function testDeleteByFilterPreparesTheBuilderAndUsesTheDefaultWriteCollection(): void
    {
        $documents = m::mock(Documents::class);
        $documents->shouldReceive('delete')
            ->once()
            ->with(['filter_by' => '(status:=active || status:=trial) && (tenant_id:=42)'])
            ->andReturn(['num_deleted' => 2]);
        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $model = new TypesenseLifecycleModel;
        $model->searchParameters = ['filter_by' => 'from_model:=true'];
        $builder = (new Builder($model, ''))
            ->options(['filter_by' => 'status:=active || status:=trial']);
        $runner = new EngineOperationRunner;
        $observer = new TypesenseEngineOperationObserver;
        $runner->observe($observer);
        $engine = $this->createPartialEngineWithConfig($client)
            ->setOperationRunner($runner, 'typesense');
        $this->assertInstanceOf(DeletesByFilter::class, $engine);
        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use ($builder, $engine): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            $givenBuilder->where('tenant_id', 42);
        });

        $engine->deleteByFilter($builder);

        $this->assertCount(1, $observer->operations);
        $this->assertSame('delete_by_filter', $observer->operations[0]->operation);
        $this->assertSame('write_index', $observer->operations[0]->index);
    }

    public function testDeleteByFilterUsesAnExplicitCollection(): void
    {
        $documents = m::mock(Documents::class);
        $documents->shouldReceive('delete')
            ->once()
            ->with(['filter_by' => 'tenant_id:=42'])
            ->andReturn(['num_deleted' => 1]);
        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('users_v2')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('users_v2')->once();
        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, ''))->within('users_v2')->where('tenant_id', 42);

        $this->createPartialEngineWithConfig($client)->deleteByFilter($builder);
    }

    public function testDeleteByFilterTreatsAMissingCollectionAsAlreadyDeleted(): void
    {
        $documents = m::mock(Documents::class);
        $documents->shouldReceive('delete')
            ->once()
            ->with(['filter_by' => 'tenant_id:=42'])
            ->andThrow(new ObjectNotFound('Collection not found'));
        $collection = m::mock(TypesenseCollection::class);
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $collections = m::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with('write_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('write_index')->once();
        $client = m::mock(TypesenseClient::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->createPartialEngineWithConfig($client)->deleteByFilter($builder);

        $this->assertTrue(true);
    }

    public function testDeleteByFilterRejectsAnEmptyFilterBeforeIo(): void
    {
        $client = m::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');
        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, '');
        $engine = $this->createPartialEngineWithConfig($client);
        $prepared = false;
        Scout::prepareBuilderUsing(function () use (&$prepared): void {
            $prepared = true;
        });

        try {
            $engine->deleteByFilter($builder);
            $this->fail('Expected empty filter deletion to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Typesense filter deletion requires a non-empty filter.', $exception->getMessage());
        }

        $this->assertTrue($prepared);
    }

    public function testDeleteByFilterDoesNotNotifyObserversForAnEmptyFilter(): void
    {
        $client = m::mock(TypesenseClient::class);
        $client->shouldNotReceive('getCollections');
        $observer = m::mock(EngineOperationObserver::class);
        $observer->shouldNotReceive('starting');
        $observer->shouldNotReceive('finished');
        $runner = new EngineOperationRunner;
        $runner->observe($observer);
        $engine = $this->createPartialEngineWithConfig($client)
            ->setOperationRunner($runner, 'typesense');
        $model = m::mock(TypesenseLifecycleModel::class)->makePartial();
        $model->shouldNotReceive('indexableAs');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Typesense filter deletion requires a non-empty filter.');

        $engine->deleteByFilter(new Builder($model, ''));
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

    public function testSearchesWithVectorQueriesUseTheMultiSearchEndpoint(): void
    {
        $multiSearch = m::mock(MultiSearch::class);
        $engine = $this->createMultiSearchEngine($multiSearch);

        $engine->shouldReceive('buildSearchParameters')->once()->andReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->shouldReceive('perform')
            ->once()
            ->with([
                'searches' => [
                    [
                        'q' => '*',
                        'query_by' => 'name',
                        'vector_query' => 'embedding:([0.1, 0.2])',
                        'collection' => 'table',
                    ],
                ],
            ])
            ->andReturn(['results' => [['found' => 1, 'hits' => [['document' => ['id' => '1']]]]]]);

        $results = $engine->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'));

        $this->assertSame(1, $results['found']);
        $this->assertSame('1', $results['hits'][0]['document']['id']);
    }

    public function testMultiSearchErrorsAreConvertedToTypesenseExceptions(): void
    {
        $multiSearch = m::mock(MultiSearch::class);
        $engine = $this->createMultiSearchEngine($multiSearch);

        $engine->shouldReceive('buildSearchParameters')->andReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->shouldReceive('perform')->andReturn([
            'results' => [['code' => 400, 'error' => 'Query string exceeds max allowed length.']],
        ]);

        $this->expectException(RequestMalformed::class);
        $this->expectExceptionMessageIs('Query string exceeds max allowed length.');

        $engine->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'));
    }

    public function testMultiSearchCreatesMissingCollectionsAndRetries(): void
    {
        $multiSearch = m::mock(MultiSearch::class);
        $engine = $this->createMultiSearchEngine($multiSearch);
        $collections = $engine->getTypesenseClient()->getCollections();
        $collections->shouldReceive('create')
            ->once()
            ->with(['name' => 'table'])
            ->andReturn(['name' => 'table']);

        $engine->shouldReceive('buildSearchParameters')->andReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->shouldReceive('perform')
            ->twice()
            ->andReturn(
                ['results' => [['code' => 404, 'error' => 'Not found.']]],
                ['results' => [['found' => 0, 'hits' => []]]],
            );

        $results = $engine->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'));

        $this->assertSame(0, $results['found']);
    }

    public function testLargeTakeUsesFixedPageSizesAndTruthfulMetadata(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $parameters = [];
        $responses = [
            ['hits' => range(1, 250), 'found' => 600, 'out_of' => 900],
            ['hits' => range(251, 350), 'found' => 600, 'out_of' => 900],
        ];
        $engine->shouldReceive('performSearch')
            ->twice()
            ->andReturnUsing(function (Builder $builder, array $options) use (&$parameters, &$responses): array {
                $parameters[] = $options;

                return array_shift($responses);
            });

        $model = new TypesenseLifecycleModel;
        $model->searchParameters = ['page' => 90, 'per_page' => 1];
        $builder = (new Builder($model, 'scout'))
            ->options(['page' => 80, 'per_page' => 2, 'include_fields' => 'id'])
            ->take(300);

        $results = $engine->search($builder);

        $this->assertCount(300, $results['hits']);
        $this->assertSame(600, $results['found']);
        $this->assertSame(900, $results['out_of']);
        $this->assertSame(1, $results['page']);
        $this->assertSame(1, $results['request_params']['page']);
        $this->assertSame(250, $results['request_params']['per_page']);
        $this->assertSame('id', $results['request_params']['include_fields']);
        $this->assertSame([1, 2], array_column($parameters, 'page'));
        $this->assertSame([250, 250], array_column($parameters, 'per_page'));
    }

    public function testLargeTakeStopsWhenTheKnownMatchCountIsExhausted(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $responses = [
            ['hits' => range(1, 250), 'found' => 500, 'out_of' => 800],
            ['hits' => range(251, 500), 'found' => 500, 'out_of' => 800],
        ];
        $engine->shouldReceive('performSearch')
            ->twice()
            ->andReturnUsing(function () use (&$responses): array {
                return array_shift($responses);
            });

        $results = $engine->search((new Builder(new TypesenseLifecycleModel, 'scout'))->take(600));

        $this->assertCount(500, $results['hits']);
        $this->assertSame(500, $results['found']);
    }

    public function testLargeTakeStopsWhenTypesenseReturnsAShortPage(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $responses = [
            ['hits' => range(1, 250), 'found' => 600, 'out_of' => 800],
            ['hits' => range(251, 275), 'found' => 600, 'out_of' => 800],
        ];
        $engine->shouldReceive('performSearch')
            ->twice()
            ->andReturnUsing(function () use (&$responses): array {
                return array_shift($responses);
            });

        $results = $engine->search((new Builder(new TypesenseLifecycleModel, 'scout'))->take(600));

        $this->assertCount(275, $results['hits']);
        $this->assertSame(600, $results['found']);
    }

    public function testLargeTakeHonorsTheConfiguredResultLimit(): void
    {
        $engine = $this->createPartialEngineWithConfig(maxTotalResults: 300);
        $responses = [
            ['hits' => range(1, 250), 'found' => 600, 'out_of' => 800],
            ['hits' => range(251, 500), 'found' => 600, 'out_of' => 800],
        ];
        $engine->shouldReceive('performSearch')
            ->twice()
            ->andReturnUsing(function () use (&$responses): array {
                return array_shift($responses);
            });

        $results = $engine->search((new Builder(new TypesenseLifecycleModel, 'scout'))->take(600));

        $this->assertCount(300, $results['hits']);
        $this->assertSame(600, $results['found']);
    }

    public function testPaginateRejectsInvalidPageSizesBeforeIo(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $engine->shouldNotReceive('performSearch');
        $builder = new Builder(new TypesenseLifecycleModel, 'scout');

        foreach ([0, -1, 251] as $perPage) {
            try {
                $engine->paginate($builder, $perPage, 1);
                $this->fail("Expected perPage [{$perPage}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Typesense pagination requires perPage to be between 1 and 250.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testPaginatePreservesValidatedPageSizeAndLargePage(): void
    {
        $engine = $this->createPartialEngineWithConfig(maxTotalResults: 10);
        $engine->shouldReceive('performSearch')
            ->once()
            ->withArgs(function (Builder $builder, array $options): bool {
                $this->assertSame(500_000_000, $options['page']);
                $this->assertSame(250, $options['per_page']);

                return true;
            })
            ->andReturn(['hits' => [], 'found' => 0]);

        $engine->paginate(new Builder(new TypesenseLifecycleModel, 'scout'), 250, 500_000_000);
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

    public function testBuildSearchParametersComposesTheWinningApplicationFilterWithBuilderFilters(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $model = new TypesenseLifecycleModel;
        $model->searchParameters = ['filter_by' => 'from_model:=true'];
        $builder = (new Builder($model, 'test'))
            ->options(['filter_by' => 'status:=active || status:=trial'])
            ->where('tenant_id', 42);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('(status:=active || status:=trial) && (tenant_id:=42)', $parameters['filter_by']);
    }

    public function testSearchCallbackReceivesFinalComposedOptions(): void
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);
        $client->shouldReceive('getCollections')->once()->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('read_index')->once()->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('read_index')->once();
        $collection->shouldReceive('getDocuments')->once()->andReturn($documents);
        $documents->shouldNotReceive('search');

        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, 'scout', function ($givenDocuments, $query, $options) use ($documents) {
            $this->assertSame($documents, $givenDocuments);
            $this->assertSame('scout', $query);
            $this->assertSame('(status:=active || status:=trial) && (tenant_id:=42)', $options['filter_by']);

            return ['found' => 0, 'hits' => []];
        });
        $builder->options(['filter_by' => 'status:=active || status:=trial'])->where('tenant_id', 42);

        $this->createPartialEngineWithConfig($client)->search($builder);
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

    public function testBuildSearchParametersKeepsEngineOwnedPagination(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $model = new TypesenseLifecycleModel;
        $model->searchParameters = ['page' => 90, 'per_page' => 1];
        $builder = (new Builder($model, 'test'))->options([
            'page' => 80,
            'per_page' => 2,
            'include_fields' => 'id,title',
        ]);

        $parameters = $engine->buildSearchParameters($builder, 7, 25);

        $this->assertSame(7, $parameters['page']);
        $this->assertSame(25, $parameters['per_page']);
        $this->assertSame('id,title', $parameters['include_fields']);
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

    public function testSemanticSearchUsesAPrecomputedQueryVector(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
            ->options(['vector' => [0.25, 0.75]])
            ->semantic(minSimilarity: 0.7);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('*', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.25, 0.75], distance_threshold: 0.3)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
    }

    public function testSemanticSearchWithoutAQueryVectorReportsThatGeneratedEmbeddingsAreUnavailable(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('AI-generated embeddings are not available in Hypervel.');

        $engine->buildSearchParameters(
            (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic(),
            1,
            10,
        );
    }

    public function testHybridSearchAcceptsAPrecomputedQueryVectorAndNormalizesWeights(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2);

        $parameters = $engine->buildSearchParameters($builder, 2, 5);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.4, 0.6], alpha: ' . (2 / 3) . ')', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
    }

    public function testSemanticSearchWithNativeEmbeddingsQueriesTheEmbeddingField(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense']);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('conceptual query', $parameters['q']);
        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('vector_query', $parameters);
        $this->assertSame('embedding', $parameters['exclude_fields']);
    }

    public function testSemanticSearchWithNativeEmbeddingsAppliesADistanceThreshold(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense']);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic(minSimilarity: 0.5);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertSame('embedding:([], distance_threshold: 0.5)', $parameters['vector_query']);
    }

    public function testSemanticSearchWithNativeEmbeddingsDropsPerFieldParameters(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense'], 'name,description');

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'infix' => 'off'])
            ->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('query_by_weights', $parameters);
        $this->assertArrayNotHasKey('num_typos', $parameters);
        $this->assertSame('off', $parameters['infix']);
    }

    public function testHybridSearchWithNativeEmbeddingsAppendsTheEmbeddingFieldToQueryBy(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense']);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name,embedding', $parameters['query_by']);
        $this->assertSame('true,false', $parameters['prefix']);
        $this->assertSame('embedding:([], alpha: 0.5)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
    }

    public function testHybridSearchWithNativeEmbeddingsExtendsPerFieldParameters(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense'], 'name,description');

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'prefix' => 'true,false', 'infix' => 'off'])
            ->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('name,description,embedding', $parameters['query_by']);
        $this->assertSame('2,1,0', $parameters['query_by_weights']);
        $this->assertSame('2,1,0', $parameters['num_typos']);
        $this->assertSame('true,false,false', $parameters['prefix']);
        $this->assertSame('off', $parameters['infix']);
    }

    #[DataProvider('hybridSearchPrefixes')]
    public function testHybridSearchWithNativeEmbeddingsDisablesPrefixSearchOnAnEmbeddingFieldAlreadyInQueryBy(
        string $queryBy,
        bool|string $prefix,
        bool|string $expected,
    ): void {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense'], $queryBy);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))
            ->options(['prefix' => $prefix])
            ->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame($queryBy, $parameters['query_by']);
        $this->assertSame($expected, $parameters['prefix']);
    }

    /**
     * Provide prefix settings for hybrid searches whose "query_by" already includes the embedding field.
     *
     * @return array<string, array{string, bool|string, bool|string}>
     */
    public static function hybridSearchPrefixes(): array
    {
        return [
            'global prefix' => ['embedding,name', true, 'false,true'],
            'per-field prefixes' => ['name,embedding,description', 'true,true,false', 'true,false,false'],
            'disabled prefix' => ['name,embedding', false, false],
        ];
    }

    public function testHybridSearchRequiresAKeywordFieldInQueryBy(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'driver' => 'typesense'], '');

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'combined query'))->hybrid();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    public function testSemanticSearchCannotBeCombinedWithACustomVectorQueryOption(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
            ->options(['vector_query' => 'embedding:([], k: 10)'])
            ->semantic();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidSemanticSearchVectors')]
    public function testSemanticSearchRejectsInvalidVectorQueries(array $options, float $minSimilarity, string $message): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))
            ->options($options)
            ->semantic($minSimilarity);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs($message);

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * Provide semantic search vectors and similarities Typesense rejects.
     *
     * @return array<string, array{array<string, mixed>, float, string}>
     */
    public static function invalidSemanticSearchVectors(): array
    {
        return [
            'empty query vector' => [['vector' => []], 0.5, 'The Typesense query [vector] must be a non-empty embedding array.'],
            'similarity above one' => [['vector' => [0.1, 0.2]], 1.5, 'The minimum similarity must be between 0 and 1.'],
        ];
    }

    public function testSemanticSearchRequiresEmbeddingSettings(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIs('No Typesense embedding settings have been configured for [' . SearchableModelWithPrecomputedEmbedding::class . '].');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * Create a partial engine mock that stubs getConfig to avoid container dependency.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $engineConfig
     */
    protected function createPartialEngineWithConfig(
        ?MockInterface $client = null,
        int $maxTotalResults = 1000,
        array $config = [],
        array $engineConfig = [],
    ): MockInterface&TypesenseEngine {
        $client = $client ?? m::mock(TypesenseClient::class);

        /** @var MockInterface&TypesenseEngine */
        $engine = m::mock(TypesenseEngine::class, [$client, $maxTotalResults, $engineConfig])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $engine->shouldReceive('getConfig')
            ->andReturnUsing(fn (string $key, mixed $default = null): mixed => $config[$key] ?? $default);

        return $engine;
    }

    /**
     * Create a partial engine with embedding settings and search parameters for the given model.
     *
     * @param array<string, mixed> $embedding
     * @param class-string<Model> $model
     */
    protected function createSemanticEngine(
        array $embedding,
        string $queryBy = 'name',
        ?MockInterface $client = null,
        string $model = SearchableModelWithPrecomputedEmbedding::class,
    ): MockInterface&TypesenseEngine {
        return $this->createPartialEngineWithConfig(
            $client,
            config: ["typesense.model-settings.{$model}.search-parameters" => ['query_by' => $queryBy]],
            engineConfig: ['model-settings' => [$model => ['embedding' => $embedding]]],
        );
    }

    /**
     * Create a partial engine whose client performs multi-searches against the "table" collection.
     */
    protected function createMultiSearchEngine(MultiSearch $multiSearch): MockInterface&TypesenseEngine
    {
        $client = m::mock(TypesenseClient::class);
        $collections = m::mock(Collections::class);
        $collection = m::mock(TypesenseCollection::class);
        $documents = m::mock(Documents::class);

        $client->shouldReceive('getMultiSearch')->andReturn($multiSearch);
        $client->shouldReceive('getCollections')->andReturn($collections);
        $collections->shouldReceive('offsetGet')->with('table')->andReturn($collection);
        $collections->shouldReceive('offsetUnset')->with('table');
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $documents->shouldNotReceive('search');

        return $this->createPartialEngineWithConfig($client);
    }
}

class TypesenseEngineOperationObserver implements EngineOperationObserver
{
    /**
     * The observed operations.
     *
     * @var array<EngineOperation>
     */
    public array $operations = [];

    public function starting(EngineOperation $operation): mixed
    {
        $this->operations[] = $operation;

        return null;
    }

    public function finished(
        EngineOperation $operation,
        mixed $token,
        ?Throwable $exception
    ): void {
    }
}

class TypesenseLifecycleModel extends Model
{
    /** @var array<string, mixed> */
    public array $searchableData = ['id' => 1, 'title' => 'Scout'];

    /** @var array<string, mixed> */
    public array $searchParameters = [];

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

    public function typesenseSearchParameters(): array
    {
        return $this->searchParameters;
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
