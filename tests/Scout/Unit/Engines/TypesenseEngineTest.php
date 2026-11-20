<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithNativeEmbedding;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModelWithPrecomputedEmbedding;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
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

#[WithConfig('ai.caching.embeddings.store', 'array')]
class TypesenseEngineTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Register the embedding provider used by semantic searches.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [AiServiceProvider::class];
    }

    /**
     * Create an engine with the given client.
     */
    protected function createEngine(?TypesenseClient $client = null): TypesenseEngine
    {
        $client = $client ?? Double::for(TypesenseClient::class)->strict();

        return new TypesenseEngine($client, 1000);
    }

    /**
     * Create a double with the declared searchable model methods.
     */
    protected function createSearchableModelDouble(): SearchableModel&DoubleInterface
    {
        return Double::for(SearchableModel::class)->strict();
    }

    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $method = new ReflectionMethod($object, $methodName);

        return $method->invoke($object, ...$parameters);
    }

    public function testFiltersMethod(): void
    {
        $engine = $this->createEngine();

        $builder = Double::for(Builder::class)->strict();
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

    public function testFilterValuesWithSpecialCharactersAreQuoted(): void
    {
        $engine = $this->createEngine();

        $builder = Double::for(Builder::class)->strict();
        $builder->wheres = [
            ['field' => 'band', 'value' => 'Rock || Roll', 'operator' => '='],
            ['field' => 'company', 'value' => 'Acme (UK)', 'operator' => '!='],
            ['field' => 'title', 'value' => 'It`s R&&D', 'operator' => '='],
            ['field' => 'name', 'value' => 'New*', 'operator' => '='],
            ['field' => 'price', 'value' => '[10..100]', 'operator' => '='],
            ['field' => 'location', 'value' => ['(48.85, 2.34, 5 km)'], 'operator' => '='],
            ['field' => 'status', 'value' => ['Rock || Roll'], 'operator' => '='],
        ];
        $builder->whereIns = [
            'company' => ['Laravel', 'Acme (UK)', 'Acme, Inc'],
        ];
        $builder->whereNotIns = [
            'band' => ['Rock || Roll'],
        ];

        $result = $this->invokeMethod($engine, 'filters', [$builder]);

        $this->assertSame(
            'band:=`Rock || Roll` && company:!=`Acme (UK)` && title:=`Its R&&D` && name:=New* && price:=[10..100] && location:(48.85, 2.34, 5 km)'
            . ' && status:`Rock || Roll` && company:=[Laravel, `Acme (UK)`, `Acme, Inc`] && band:!=[`Rock || Roll`]',
            $result
        );
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
        $this->assertEquals('tags:tag1tag2tag3', $this->invokeMethod($engine, 'parseWhereFilter', [['tag1', 'tag2', 'tag3'], 'tags']));
        $this->assertEquals('status:!=inactive', $this->invokeMethod($engine, 'parseWhereFilter', [['inactive'], 'status', '!=']));
    }

    #[DataProvider('unsupportedOperatorValues')]
    public function testParseWhereFilterRejectsUnsupportedOperators(array|string $value): void
    {
        $engine = $this->createEngine();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unsupported Typesense filter operator [LIKE].');

        $this->invokeMethod($engine, 'parseWhereFilter', [$value, 'status', 'LIKE']);
    }

    public static function unsupportedOperatorValues(): array
    {
        return [
            'scalar' => ['active'],
            'array' => [['active']],
        ];
    }

    public function testParseWhereInFilterMethod(): void
    {
        $engine = $this->createEngine();

        $this->assertEquals(
            'category:=[electronics, books]',
            $this->invokeMethod($engine, 'parseWhereInFilter', [['electronics', 'books'], 'category'])
        );
        $this->assertEquals('id:=[1, 2, 3]', $this->invokeMethod($engine, 'parseWhereInFilter', [[1, 2, 3], 'id']));
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
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->never();

        $engine = $this->createEngine($client);

        $engine->update(new EloquentCollection([]));
    }

    public function testUpdateTransformsBeforeTouchingTypesense(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->never();

        $model = new TypesenseLifecycleModel;
        $model->searchableData = [];

        $this->createEngine($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateImportsIntoIndexableCollection(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')->never();
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')
            ->with([['id' => 1, 'title' => 'Scout']], ['action' => 'upsert'])
            ->returns([['success' => true, 'document' => '{"id":1}']]);

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateMethodWithEmplaceAction(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')
            ->with([['id' => 1, 'title' => 'Scout']], ['action' => 'emplace'])
            ->returns([['success' => true, 'document' => '{"id":1}']]);

        $engine = $this->createPartialEngineWithConfig($client, config: ['typesense.import_action' => 'emplace']);

        $engine->update(new EloquentCollection([new TypesenseLifecycleModel]));
    }

    public function testUpdatePreparesTheFinalSearchableDocument(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')
            ->with([['id' => 1, 'title' => 'Scout', 'tenant_id' => 42]], ['action' => 'upsert'])
            ->returns([['success' => true, 'document' => '{"id":1}']]);

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
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('table')->returns($collection);
        $collections->expects('offsetUnset')->with('table');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')
            ->with([
                ['id' => 10, 'name' => 'First', 'embedding' => [0.1, 0.2], 'embedding_vector' => [0.1, 0.2]],
                ['id' => 20, 'name' => 'Second', 'embedding' => [0.3, 0.4], 'embedding_vector' => [0.3, 0.4]],
            ], ['action' => 'upsert'])
            ->returns([['success' => true], ['success' => true]]);

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

    public function testUpdateAddsPrecomputedAndGeneratedEmbeddingsToDocuments(): void
    {
        Embeddings::fake([[[0.3, 0.4]]])->preventStrayEmbeddings();

        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('table')->returns($collection);
        $collections->expects('offsetUnset')->with('table');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')->with([
            ['id' => 10, 'name' => 'Precomputed', 'embedding' => [0.1, 0.2]],
            ['id' => 20, 'name' => 'Generate this', 'embedding' => [0.3, 0.4]],
        ], ['action' => 'upsert'])->returns([['success' => true], ['success' => true]]);

        $engine = $this->createSemanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
            'provider' => 'openai',
            'model' => 'text-embedding-test',
        ], client: $client);

        $precomputed = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'Precomputed']);
        $precomputed->setAttribute('embedding', [0.1, 0.2]);
        $generated = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Generate this']);

        $engine->update($precomputed->newCollection([$precomputed, $generated]));

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Generate this']
            && $prompt->dimensions === 2
            && $prompt->provider->name() === 'openai'
            && $prompt->model === 'text-embedding-test');
    }

    public function testUpdateDoesNotGenerateEmbeddingsWhenUsingNativeEmbeddings(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('table')->returns($collection);
        $collections->expects('offsetUnset')->with('table');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')
            ->with([['id' => 1, 'name' => 'Model 1']], ['action' => 'upsert'])
            ->returns([['success' => true]]);

        $engine = $this->createSemanticEngine(
            ['attribute' => 'embedding', 'driver' => 'typesense'],
            client: $client,
            model: SearchableModelWithNativeEmbedding::class,
        );

        $model = new SearchableModelWithNativeEmbedding(['id' => 1, 'name' => 'Model 1']);

        $engine->update($model->newCollection([$model]));
    }

    public function testMissingCollectionIsCreatedUsingTheIndexableName(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->times(2)->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')
            ->with([
                'fields' => [['name' => 'title', 'type' => 'string']],
                'name' => 'write_index',
            ])
            ->returns(['name' => 'write_index']);
        $collection->expects('getDocuments')->times(2)->returns($documents);
        $attempts = 0;
        $documents->expects('import')->times(2)->resolves(function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new ObjectNotFound('Collection not found');
            }

            return [['success' => true, 'document' => '{"id":1}']];
        });

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testMissingCollectionPreparesSettingsBeforeTheAuthoritativeName(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->times(2)->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')
            ->with([
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'tenant_id', 'type' => 'int64'],
                ],
                'name' => 'write_index',
            ]);
        $collection->expects('getDocuments')->times(2)->returns($documents);
        $attempts = 0;
        $documents->expects('import')->times(2)->resolves(function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new ObjectNotFound('Collection not found');
            }

            return [['success' => true, 'document' => '{"id":1}']];
        });

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
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->times(2)->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')->throws(new ObjectAlreadyExists('Collection exists'));
        $collection->expects('getDocuments')->times(2)->returns($documents);
        $attempts = 0;
        $documents->expects('import')->times(2)->resolves(function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new ObjectNotFound('Collection not found');
            }

            return [['success' => true, 'document' => '{"id":1}']];
        });

        $model = new TypesenseLifecycleModel;

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdatePropagatesOtherCollectionCreateFailures(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $exception = new TypesenseClientError('Invalid schema');

        $client->expects('getCollections')->times(2)->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')->throws($exception);
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')->throws(new ObjectNotFound('Collection not found'));

        $model = new TypesenseLifecycleModel;

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdatePropagatesNonMissingImportFailure(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $exception = new TypesenseClientError('Authentication failed');

        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')->never();
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('import')->throws($exception);

        $model = new TypesenseLifecycleModel;

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesDocumentsFromIndex(): void
    {
        $model = $this->createSearchableModelDouble();
        $model->allows('indexableAs')->returns('write_index');
        $model->allows('getScoutKey')->returns(123);

        $document = Double::for(Document::class)->strict();
        $document->expects('delete')->returns([]);

        $documents = Double::for(Documents::class)->strict();
        $documents->allows('offsetGet')
            ->with('123')
            ->returns($document);

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->allows('getDocuments')->returns($documents);

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $this->createEngine($client)->delete(new EloquentCollection([$model]));
    }

    public function testDeleteUsesStoredScoutKeyFromRemoveableCollection(): void
    {
        $document = Double::for(Document::class)->strict();
        $document->expects('delete')->returns([]);

        $documents = Double::for(Documents::class)->strict();
        $documents->expects('offsetGet')->with('stored-scout-key')->returns($document);

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('getDocuments')->returns($documents);

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $model = $this->createSearchableModelDouble();
        $model->allows('getScoutKeyName')->returns('scout_id');
        $model->allows('getAttribute')->with('scout_id')->returns('stored-scout-key');
        $model->allows('indexableAs')->returns('write_index');

        $this->createEngine($client)->delete(new RemoveableScoutCollection([$model]));
    }

    public function testDeleteWithEmptyCollectionDoesNothing(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->never();

        $engine = $this->createEngine($client);

        $engine->delete(new EloquentCollection([]));
    }

    public function testDeleteDocumentReturnsEmptyArrayWhenDocumentNotFound(): void
    {
        $model = $this->createSearchableModelDouble();
        $model->allows('getScoutKey')->returns(123);

        $document = Double::for(Document::class)->strict();
        $document->expects('delete')->throws(new ObjectNotFound('Document not found'));

        $documents = Double::for(Documents::class)->strict();
        $documents->allows('offsetGet')
            ->with('123')
            ->returns($document);

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->allows('getDocuments')->returns($documents);

        $model->allows('indexableAs')->returns('write_index');

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $this->createEngine($client)->delete(new EloquentCollection([$model]));
    }

    public function testDeleteDocumentThrowsOnNonNotFoundErrors(): void
    {
        $model = $this->createSearchableModelDouble();
        $model->allows('getScoutKey')->returns(123);

        $document = Double::for(Document::class)->strict();
        $document->expects('delete')->throws(new TypesenseClientError('Connection failed'));

        $documents = Double::for(Documents::class)->strict();
        $documents->allows('offsetGet')
            ->with('123')
            ->returns($document);

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->allows('getDocuments')->returns($documents);

        $model->allows('indexableAs')->returns('write_index');

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $this->expectException(TypesenseClientError::class);
        $this->expectExceptionMessageIs('Connection failed');

        $this->createEngine($client)->delete(new EloquentCollection([$model]));
    }

    public function testFlushDeletesCollection(): void
    {
        $model = $this->createSearchableModelDouble();
        $model->allows('indexableAs')->returns('write_index');

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('delete');

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $this->createEngine($client)->flush($model);
    }

    public function testFlushTreatsMissingCollectionAsAlreadyFlushed(): void
    {
        $model = $this->createSearchableModelDouble();
        $model->allows('indexableAs')->returns('write_index');

        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('delete')->throws(new ObjectNotFound('Collection not found'));

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $collections->expects('create')->never();

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $this->createEngine($client)->flush($model);
    }

    public function testDeleteByFilterPreparesTheBuilderAndUsesTheDefaultWriteCollection(): void
    {
        $documents = Double::for(Documents::class)->strict();
        $documents->expects('delete')
            ->with(['filter_by' => '(status:=active || status:=trial) && (tenant_id:=42)'])
            ->returns(['num_deleted' => 2]);
        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('getDocuments')->returns($documents);
        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

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
        $documents = Double::for(Documents::class)->strict();
        $documents->expects('delete')
            ->with(['filter_by' => 'tenant_id:=42'])
            ->returns(['num_deleted' => 1]);
        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('getDocuments')->returns($documents);
        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('users_v2')->returns($collection);
        $collections->expects('offsetUnset')->with('users_v2');
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, ''))->within('users_v2')->where('tenant_id', 42);

        $this->createPartialEngineWithConfig($client)->deleteByFilter($builder);
    }

    public function testDeleteByFilterTreatsAMissingCollectionAsAlreadyDeleted(): void
    {
        $documents = Double::for(Documents::class)->strict();
        $documents->expects('delete')
            ->with(['filter_by' => 'tenant_id:=42'])
            ->throws(new ObjectNotFound('Collection not found'));
        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('getDocuments')->returns($documents);
        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('write_index')->returns($collection);
        $collections->expects('offsetUnset')->with('write_index');
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->createPartialEngineWithConfig($client)->deleteByFilter($builder);
    }

    public function testDeleteByFilterRejectsAnEmptyFilterBeforeIo(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->never();
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
        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->never();
        $observer = Double::for(EngineOperationObserver::class)->strict();
        $observer->expects('starting')->never();
        $observer->expects('finished')->never();
        $runner = new EngineOperationRunner;
        $runner->observe($observer);
        $engine = $this->createPartialEngineWithConfig($client)
            ->setOperationRunner($runner, 'typesense');
        $model = Double::for(new TypesenseLifecycleModel)->passthru();
        $model->expects('indexableAs')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Typesense filter deletion requires a non-empty filter.');

        $engine->deleteByFilter(new Builder($model, ''));
    }

    public function testDeleteIndexCallsTypesenseDelete(): void
    {
        $collection = Double::for(TypesenseCollection::class)->strict();
        $collection->expects('delete')
            ->returns(['name' => 'test_index']);

        $collections = Double::for(Collections::class)->strict();
        $collections->expects('offsetGet')->with('test_index')->returns($collection);
        $collections->expects('offsetUnset')->with('test_index');

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->returns($collections);

        $engine = $this->createEngine($client);

        $result = $engine->deleteIndex('test_index');

        $this->assertEquals(['name' => 'test_index'], $result);
    }

    public function testCollectionHandlesAreDetachedAndMagicNamesRemainUsable(): void
    {
        $collections = new Collections(Double::for(ApiCall::class)->strict());

        $client = Double::for(TypesenseClient::class)->strict();
        $client->expects('getCollections')->times(2)->returns($collections);

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
        $client = Double::for(TypesenseClient::class)->strict();
        $engine = $this->createEngine($client);

        $this->assertSame($client, $engine->getTypesenseClient());
    }

    public function testSearchUsesExplicitWithinCollection(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('custom_index')->returns($collection);
        $collections->expects('offsetUnset')->with('custom_index');
        $collections->expects('create')->never();
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('search')
            ->with(Argument::satisfies(fn (array $parameters): bool => $parameters['q'] === 'scout'))
            ->returns(['found' => 0, 'hits' => []]);

        $model = new TypesenseLifecycleModel;
        $builder = (new Builder($model, 'scout'))->within('custom_index');

        $this->assertSame(
            ['found' => 0, 'hits' => []],
            $this->createPartialEngineWithConfig($client)->search($builder),
        );
    }

    public function testSearchCreatesMissingSearchableCollectionAndRetriesOnce(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->expects('getCollections')->times(2)->returns($collections);
        $collections->expects('offsetGet')->with('read_index')->returns($collection);
        $collections->expects('offsetUnset')->with('read_index');
        $collections->expects('create')
            ->with([
                'fields' => [['name' => 'title', 'type' => 'string']],
                'name' => 'read_index',
            ])
            ->returns(['name' => 'read_index']);
        $collection->expects('getDocuments')->returns($documents);
        $attempts = 0;
        $documents->expects('search')->times(2)->resolves(function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new ObjectNotFound('Collection not found');
            }

            return ['found' => 0, 'hits' => []];
        });

        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, 'scout');

        $this->createPartialEngineWithConfig($client)->search($builder);
    }

    public function testSearchPropagatesNonMissingFailure(): void
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $exception = new TypesenseClientError('Authentication failed');

        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('read_index')->returns($collection);
        $collections->expects('offsetUnset')->with('read_index');
        $collections->expects('create')->never();
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('search')->throws($exception);

        $model = new TypesenseLifecycleModel;
        $builder = new Builder($model, 'scout');

        $this->expectExceptionObject($exception);

        $this->createPartialEngineWithConfig($client)->search($builder);
    }

    public function testSearchesWithVectorQueriesUseTheMultiSearchEndpoint(): void
    {
        $multiSearch = Double::for(MultiSearch::class)->strict();
        $engine = $this->createMultiSearchEngine($multiSearch);

        $engine->expects('buildSearchParameters')->returns([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->expects('perform')
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
            ->returns(['results' => [['found' => 1, 'hits' => [['document' => ['id' => '1']]]]]]);

        $results = $engine->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'));

        $this->assertSame(1, $results['found']);
        $this->assertSame('1', $results['hits'][0]['document']['id']);
    }

    public function testMultiSearchErrorsAreConvertedToTypesenseExceptions(): void
    {
        $multiSearch = Double::for(MultiSearch::class)->strict();
        $engine = $this->createMultiSearchEngine($multiSearch);

        $engine->allows('buildSearchParameters')->returns([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->allows('perform')->returns([
            'results' => [['code' => 400, 'error' => 'Query string exceeds max allowed length.']],
        ]);

        $this->expectException(RequestMalformed::class);
        $this->expectExceptionMessageIs('Query string exceeds max allowed length.');

        $engine->search(new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'));
    }

    public function testMultiSearchCreatesMissingCollectionsAndRetries(): void
    {
        $multiSearch = Double::for(MultiSearch::class)->strict();
        $engine = $this->createMultiSearchEngine($multiSearch);
        $collections = $engine->getTypesenseClient()->getCollections();
        $collections->expects('create')
            ->with(['name' => 'table'])
            ->returns(['name' => 'table']);

        $engine->allows('buildSearchParameters')->returns([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->expects('perform')
            ->times(2)
            ->returns(
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
        $engine->expects('performSearch')
            ->times(2)
            ->resolves(function (Builder $builder, array $options) use (&$parameters, &$responses): array {
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
        $engine->expects('performSearch')
            ->times(2)
            ->resolves(function () use (&$responses): array {
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
        $engine->expects('performSearch')
            ->times(2)
            ->resolves(function () use (&$responses): array {
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
        $engine->expects('performSearch')
            ->times(2)
            ->resolves(function () use (&$responses): array {
                return array_shift($responses);
            });

        $results = $engine->search((new Builder(new TypesenseLifecycleModel, 'scout'))->take(600));

        $this->assertCount(300, $results['hits']);
        $this->assertSame(600, $results['found']);
    }

    public function testPaginateRejectsInvalidPageSizesBeforeIo(): void
    {
        $engine = $this->createPartialEngineWithConfig();
        $engine->expects('performSearch')->never();
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
        $engine->expects('performSearch')
            ->with(Argument::type(Builder::class), Argument::capture($options))
            ->returns(['hits' => [], 'found' => 0]);

        $engine->paginate(new Builder(new TypesenseLifecycleModel, 'scout'), 250, 500_000_000);
        $this->assertSame(500_000_000, $options['page']);
        $this->assertSame(250, $options['per_page']);
    }

    public function testMapReturnsEmptyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelDouble();
        $model->allows('newCollection')->returns(new EloquentCollection);

        $builder = Double::for(Builder::class)->strict();
        $results = ['found' => 0, 'hits' => []];

        $mapped = $engine->map($builder, $results, $model);

        $this->assertTrue($mapped->isEmpty());
    }

    public function testLazyMapReturnsLazyCollectionWhenNoResults(): void
    {
        $engine = $this->createEngine();

        $model = $this->createSearchableModelDouble();
        $model->allows('newCollection')->returns(new EloquentCollection);

        $builder = Double::for(Builder::class)->strict();
        $results = ['found' => 0, 'hits' => []];

        $lazyMapped = $engine->lazyMap($builder, $results, $model);

        $this->assertInstanceOf(\Hypervel\Support\LazyCollection::class, $lazyMapped);
    }

    public function testBuildSearchParametersIncludesBasicParameters(): void
    {
        $engine = $this->createPartialEngineWithConfig();

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();
        $client->expects('getCollections')->returns($collections);
        $collections->expects('offsetGet')->with('read_index')->returns($collection);
        $collections->expects('offsetUnset')->with('read_index');
        $collection->expects('getDocuments')->returns($documents);
        $documents->expects('search')->never();

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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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

        $model = $this->createSearchableModelDouble();

        $builder = Double::for(Builder::class)->strict();
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
        Embeddings::fake()->preventStrayEmbeddings();

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

    public function testSemanticSearchGeneratesAQueryVector(): void
    {
        $engine = $this->createSemanticEngine(['attribute' => 'embedding', 'dimensions' => 2]);
        Embeddings::fake([[[0.25, 0.75]]])->preventStrayEmbeddings();

        $builder = (new Builder(new SearchableModelWithPrecomputedEmbedding, 'conceptual query'))->semantic(minSimilarity: 0.7);
        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame($parameters, $engine->buildSearchParameters($builder, 1, 10));

        $this->assertSame('*', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.25, 0.75], distance_threshold: 0.3)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['conceptual query']);
    }

    public function testHybridSearchAcceptsAPrecomputedQueryVectorAndNormalizesWeights(): void
    {
        Embeddings::fake()->preventStrayEmbeddings();

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
        Embeddings::fake()->preventStrayEmbeddings();

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
     * Create an initialized engine double with isolated configuration.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $engineConfig
     */
    protected function createPartialEngineWithConfig(
        ?TypesenseClient $client = null,
        int $maxTotalResults = 1000,
        array $config = [],
        array $engineConfig = [],
    ): TypesenseEngine&DoubleInterface {
        $client = $client ?? Double::for(TypesenseClient::class)->strict();

        $engine = Double::for(new TypesenseEngine($client, $maxTotalResults, $engineConfig))->passthru();

        $engine->allows('getConfig')
            ->resolves(fn (string $key, mixed $default = null): mixed => $config[$key] ?? $default);

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
        ?TypesenseClient $client = null,
        string $model = SearchableModelWithPrecomputedEmbedding::class,
    ): TypesenseEngine&DoubleInterface {
        return $this->createPartialEngineWithConfig(
            $client,
            config: ["typesense.model-settings.{$model}.search-parameters" => ['query_by' => $queryBy]],
            engineConfig: ['model-settings' => [$model => ['embedding' => $embedding]]],
        );
    }

    /**
     * Create a partial engine whose client performs multi-searches against the "table" collection.
     */
    protected function createMultiSearchEngine(MultiSearch $multiSearch): TypesenseEngine&DoubleInterface
    {
        $client = Double::for(TypesenseClient::class)->strict();
        $collections = Double::for(Collections::class)->strict();
        $collection = Double::for(TypesenseCollection::class)->strict();
        $documents = Double::for(Documents::class)->strict();

        $client->allows('getMultiSearch')->returns($multiSearch);
        $client->allows('getCollections')->returns($collections);
        $collections->allows('offsetGet')->with('table')->returns($collection);
        $collections->allows('offsetUnset')->with('table');
        $collection->allows('getDocuments')->returns($documents);
        $documents->expects('search')->never();

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
