<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\DeletesByFilter;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Scout;
use Hypervel\Scout\Searchable;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\TimeOutException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class MeilisearchEngineTest extends TestCase
{
    public function testUpdateAddsDocumentsToIndex(): void
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

    public function testUpdateEmptyCollectionDoesNothing(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');

        $engine = new MeilisearchEngine($client);
        $engine->update(new EloquentCollection);

        $this->assertTrue(true);
    }

    public function testUpdateWithSoftDeletesAddsSoftDeleteMetadata(): void
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

    public function testUpdatePreparesTheFinalSearchableDocument(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('test_index')->andReturn($index);
        $index->shouldReceive('addDocuments')
            ->once()
            ->with([[
                'title' => 'Test',
                '__soft_deleted' => 0,
                'id' => 1,
                'tenant_id' => 42,
            ]], 'id');

        $engine = new MeilisearchEngine($client);
        $model = $this->createSearchableModelMock();
        $model->shouldReceive('indexableAs')->andReturn('test_index');
        $model->shouldReceive('toSearchableArray')->andReturn(['title' => 'Test']);
        $model->shouldReceive('scoutMetadata')->andReturn(['__soft_deleted' => 0]);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutKey')->andReturn(1);

        Scout::prepareSearchableDocumentUsing(function (
            array $document,
            Model $givenModel,
            Engine $givenEngine
        ) use ($model, $engine): array {
            $this->assertSame([
                'title' => 'Test',
                '__soft_deleted' => 0,
                'id' => 1,
            ], $document);
            $this->assertSame($model, $givenModel);
            $this->assertSame($engine, $givenEngine);

            return [...$document, 'tenant_id' => 42];
        });

        $engine->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesDocumentsFromIndex(): void
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

    public function testDeleteWithRemoveableScoutCollectionUsesStoredScoutKey(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('chirps')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('deleteDocuments')
            ->once()
            ->with(['stored-scout-key']);

        $engine = new MeilisearchEngine($client);

        $model = new MeilisearchTestChirpModel;
        $model->setRawAttributes(['scout_id' => 'stored-scout-key']);

        $engine->delete(new RemoveableScoutCollection([$model]));
    }

    public function testDeleteEmptyCollectionDoesNothing(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');

        $engine = new MeilisearchEngine($client);
        $engine->delete(new EloquentCollection);

        $this->assertTrue(true);
    }

    public function testSearchPerformsSearchOnMeilisearch(): void
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

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'test query');

        $result = $engine->search($builder);

        $this->assertEquals(['hits' => [], 'totalHits' => 0], $result);
    }

    public function testSearchWithFilters(): void
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
                return $params['filter'] === 'status="active" AND score>=10 AND label!="draft\"review\\\copy" AND archived_at IS NOT NULL AND tags IN ["a\"b\\\c", true] AND hidden NOT IN ["draft", false]';
            }))
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'query');
        $builder->where('status', 'active')
            ->where('score', '>=', 10)
            ->where('label', '!=', 'draft"review\copy')
            ->where('archived_at', '!=', null)
            ->whereIn('tags', ['a"b\c', true])
            ->whereNotIn('hidden', ['draft', false]);

        $engine->search($builder);
    }

    #[DataProvider('filterCombinations')]
    public function testSearchComposesApplicationAndBuilderFilters(
        string|array|null $applicationFilter,
        bool $hasBuilderFilter,
        string|array|null $expectedFilter
    ): void {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('test_index')->andReturn($index);
        $expected = $expectedFilter === null ? [] : ['filter' => $expectedFilter];
        $index->shouldReceive('rawSearch')->once()->with('query', $expected);

        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $builder = new Builder($model, 'query');

        if ($applicationFilter !== null) {
            $builder->options(['filter' => $applicationFilter]);
        }

        if ($hasBuilderFilter) {
            $builder->where('tenant_id', 42);
        }

        $engine->search($builder);
    }

    /**
     * Provide application and Builder filter combinations.
     *
     * @return array<string, array{null|array<mixed>|string, bool, null|array<mixed>|string}>
     */
    public static function filterCombinations(): array
    {
        return [
            'neither side' => [null, false, null],
            'application string only' => ['status="active" OR status="trial"', false, 'status="active" OR status="trial"'],
            'whitespace application and builder' => ['   ', true, 'tenant_id=42'],
            'builder only' => [null, true, 'tenant_id=42'],
            'both string sides' => [
                'status="active" OR status="trial"',
                true,
                '(status="active" OR status="trial") AND (tenant_id=42)',
            ],
            'application array only' => [
                [['status="active"', 'status="trial"'], 'visible=true'],
                false,
                [['status="active"', 'status="trial"'], 'visible=true'],
            ],
            'application array and builder' => [
                [['status="active"', 'status="trial"'], 'visible=true'],
                true,
                [['status="active"', 'status="trial"'], 'visible=true', 'tenant_id=42'],
            ],
        ];
    }

    public function testPaginateComposesApplicationAndBuilderFilters(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('test_index')->andReturn($index);
        $index->shouldReceive('rawSearch')->once()->with('query', [
            'filter' => '(status="active" OR status="trial") AND (tenant_id=42)',
            'hitsPerPage' => 15,
            'page' => 2,
        ]);

        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $builder = (new Builder($model, 'query'))
            ->options(['filter' => 'status="active" OR status="trial"'])
            ->where('tenant_id', 42);

        $engine->paginate($builder, 15, 2);
    }

    public function testSearchCallbackReceivesFinalComposedOptions(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('test_index')->andReturn($index);
        $index->shouldNotReceive('rawSearch');
        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $builder = new Builder($model, 'query', function ($givenIndex, $query, $options) use ($index) {
            $this->assertSame($index, $givenIndex);
            $this->assertSame('query', $query);
            $this->assertSame([
                'filter' => [['status="active"', 'status="trial"'], 'tenant_id=42'],
            ], $options);

            return ['hits' => [], 'totalHits' => 0];
        });
        $builder->options(['filter' => [['status="active"', 'status="trial"']]])->where('tenant_id', 42);

        $engine->search($builder);
    }

    public function testSearchWithBackedEnumFilters(): void
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
                return $params['filter'] === 'status="published" AND priority=1 AND statuses IN ["draft", "published"] AND priorities NOT IN [1, 2]';
            }))
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $engine = new MeilisearchEngine($client);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'query');
        $builder->where('status', MeilisearchStringStatus::Published)
            ->where('priority', MeilisearchIntegerPriority::High)
            ->whereIn('statuses', [MeilisearchStringStatus::Draft, MeilisearchStringStatus::Published])
            ->whereNotIn('priorities', [MeilisearchIntegerPriority::High, MeilisearchIntegerPriority::Low]);

        $engine->search($builder);
    }

    public function testPaginatePerformsPaginatedSearch(): void
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

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('searchableAs')->andReturn('test_index');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $builder = new Builder($model, 'query');

        $engine->paginate($builder, 15, 2);
    }

    public function testMapIdsReturnsEmptyCollectionIfNoHits(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 0,
            'hits' => [],
        ], 'id');

        $this->assertCount(0, $results);
    }

    public function testMapIdsReturnsCorrectPrimaryKeys(): void
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

    public function testMapCorrectlyMapsResultsToModels(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        // Create a mock searchable model that tracks scout metadata
        $searchableModel = m::mock(Model::class . ', ' . SearchableInterface::class);
        $searchableModel->shouldReceive('getScoutKey')->andReturn(1);
        $searchableModel->shouldReceive('withScoutMetadata')
            ->with('_rankingScore', 0.86)
            ->once()
            ->andReturnSelf();

        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutModelsByIds')->andReturn(new EloquentCollection([$searchableModel]));
        $model->shouldReceive('newCollection')
            ->andReturnUsing(fn ($models) => new EloquentCollection($models));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ], $model);

        $this->assertCount(1, $results);
    }

    public function testMapReturnsEmptyCollectionWhenNoHits(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection);

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['hits' => []], $model);

        $this->assertCount(0, $results);
    }

    public function testMapRespectsOrder(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        // Create mock models
        $mockModels = [];
        foreach ([1, 2, 3, 4] as $id) {
            $mock = m::mock(Model::class . ', ' . SearchableInterface::class);
            $mock->shouldReceive('getScoutKey')->andReturn($id);
            $mockModels[] = $mock;
        }

        $models = new EloquentCollection($mockModels);

        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models);
        $model->shouldReceive('newCollection')
            ->andReturnUsing(fn ($models) => new EloquentCollection($models));

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

    public function testLazyMapReturnsEmptyCollectionWhenNoHits(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('newCollection')->andReturn(new EloquentCollection);

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['hits' => []], $model);

        $this->assertInstanceOf(LazyCollection::class, $results);
        $this->assertCount(0, $results);
    }

    public function testGetTotalCountReturnsTotalHits(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(3, $engine->getTotalCount(['totalHits' => 3]));
    }

    public function testGetTotalCountReturnsEstimatedTotalHits(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(5, $engine->getTotalCount(['estimatedTotalHits' => 5]));
    }

    public function testGetTotalCountReturnsZeroWhenNoCountAvailable(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame(0, $engine->getTotalCount([]));
    }

    public function testFlushDeletesAllDocuments(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $index->shouldReceive('deleteAllDocuments')->once();

        $engine = new MeilisearchEngine($client);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('indexableAs')->andReturn('test_index');

        $engine->flush($model);
    }

    public function testDeleteByFilterPreparesTheBuilderAndWaitsForTheDefaultWriteIndex(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('users_write')->andReturn($index);
        $index->shouldReceive('deleteDocuments')
            ->once()
            ->with(['filter' => [['visibility=true', 'visibility=false'], 'status="active" AND tenant_id=42']])
            ->andReturn(['taskUid' => 123]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(123, 500_000, 5_000)
            ->andReturn(['status' => 'succeeded']);

        $engine = new MeilisearchEngine($client);
        $this->assertInstanceOf(DeletesByFilter::class, $engine);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('indexableAs')->once()->andReturn('users_write');
        $model->shouldNotReceive('searchableAs');
        $builder = (new Builder($model, ''))
            ->options(['filter' => [['visibility=true', 'visibility=false']]])
            ->where('status', 'active');

        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use ($builder, $engine): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            $givenBuilder->where('tenant_id', 42);
        });

        $engine->deleteByFilter($builder);
    }

    public function testDeleteByFilterUsesAnExplicitIndex(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('users_v2')->andReturn($index);
        $index->shouldReceive('deleteDocuments')
            ->once()
            ->with(['filter' => 'tenant_id=42'])
            ->andReturn(['taskUid' => 123]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(123, 500_000, 5_000)
            ->andReturn(['status' => 'succeeded']);

        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldNotReceive('indexableAs');
        $builder = (new Builder($model, ''))->within('users_v2')->where('tenant_id', 42);

        $engine->deleteByFilter($builder);
    }

    public function testDeleteByFilterTreatsAMissingIndexAsAlreadyDeleted(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('users')->andReturn($index);
        $index->shouldReceive('deleteDocuments')
            ->once()
            ->with(['filter' => 'tenant_id=42'])
            ->andReturn(['taskUid' => 123]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(123, 500_000, 5_000)
            ->andReturn([
                'status' => 'failed',
                'error' => ['code' => 'index_not_found'],
            ]);

        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('indexableAs')->once()->andReturn('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        (new MeilisearchEngine($client))->deleteByFilter($builder);

        $this->assertTrue(true);
    }

    public function testDeleteByFilterRejectsAnEmptyFilterBeforeIo(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');
        $client->shouldNotReceive('waitForTask');
        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldNotReceive('indexableAs');
        $builder = new Builder($model, '');
        $prepared = false;
        Scout::prepareBuilderUsing(function () use (&$prepared): void {
            $prepared = true;
        });

        try {
            $engine->deleteByFilter($builder);
            $this->fail('Expected empty filter deletion to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Meilisearch filter deletion requires a non-empty filter.', $exception->getMessage());
        }

        $this->assertTrue($prepared);
    }

    public function testDeleteByFilterRejectsAnUnsuccessfulTask(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->once()->with('users')->andReturn($index);
        $index->shouldReceive('deleteDocuments')->once()->andReturn(['taskUid' => 123]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(123, 500_000, 5_000)
            ->andReturn(['status' => 'failed']);

        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('indexableAs')->andReturn('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Meilisearch filter deletion did not complete successfully.');

        $engine->deleteByFilter($builder);
    }

    public function testDeleteByFilterPreservesSdkTimeouts(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $timeout = new TimeOutException('Timed out waiting for deletion.');
        $client->shouldReceive('index')->once()->with('users')->andReturn($index);
        $index->shouldReceive('deleteDocuments')->once()->andReturn(['taskUid' => 123]);
        $client->shouldReceive('waitForTask')
            ->once()
            ->with(123, 500_000, 5_000)
            ->andThrow($timeout);

        $engine = new MeilisearchEngine($client);
        $model = m::mock(MeilisearchTestSearchableModel::class);
        $model->shouldReceive('indexableAs')->andReturn('users');
        $builder = (new Builder($model, ''))->where('tenant_id', 42);

        $this->expectExceptionObject($timeout);

        $engine->deleteByFilter($builder);
    }

    public function testCreateIndexCreatesNewIndex(): void
    {
        $client = m::mock(Client::class);

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andThrow($this->apiException(404, 'index_not_found'));

        $taskInfo = ['taskUid' => 1, 'indexUid' => 'test_index', 'status' => 'enqueued'];
        $client->shouldReceive('createIndex')
            ->with('test_index', ['primaryKey' => 'id'])
            ->once()
            ->andReturn($taskInfo);

        $engine = new MeilisearchEngine($client);

        $result = $engine->createIndex('test_index', ['primaryKey' => 'id']);

        $this->assertSame($taskInfo, $result);
    }

    public function testCreateIndexReturnsExistingIndex(): void
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

    public function testCreateIndexPropagatesNonNotFoundLookupFailure(): void
    {
        $client = m::mock(Client::class);
        $exception = $this->apiException(401, 'invalid_api_key');

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andThrow($exception);

        $client->shouldNotReceive('createIndex');

        $engine = new MeilisearchEngine($client);

        $this->expectExceptionObject($exception);

        $engine->createIndex('test_index');
    }

    public function testCreateIndexReturnsIndexWhenCreationLosesRace(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andThrow($this->apiException(404, 'index_not_found'));

        $client->shouldReceive('createIndex')
            ->with('test_index', [])
            ->once()
            ->andThrow($this->apiException(409, 'index_already_exists'));

        $client->shouldReceive('index')
            ->with('test_index')
            ->once()
            ->andReturn($index);

        $engine = new MeilisearchEngine($client);

        $this->assertSame($index, $engine->createIndex('test_index'));
    }

    public function testCreateIndexPropagatesOtherCreateConflict(): void
    {
        $client = m::mock(Client::class);
        $exception = $this->apiException(409, 'invalid_index_uid');

        $client->shouldReceive('getIndex')
            ->with('test_index')
            ->once()
            ->andThrow($this->apiException(404, 'index_not_found'));

        $client->shouldReceive('createIndex')
            ->with('test_index', [])
            ->once()
            ->andThrow($exception);

        $client->shouldNotReceive('index');

        $engine = new MeilisearchEngine($client);

        $this->expectExceptionObject($exception);

        $engine->createIndex('test_index');
    }

    public function testDeleteIndexDeletesIndex(): void
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

    public function testDeleteAllIndexesWithPrefixScopesToPrefixedUids(): void
    {
        $client = m::mock(Client::class);

        $testUsers = m::mock(Indexes::class);
        $testUsers->shouldReceive('getUid')->andReturn('test_users');
        $testUsers->shouldReceive('delete')->once()->andReturn(['taskUid' => 1]);

        $testPosts = m::mock(Indexes::class);
        $testPosts->shouldReceive('getUid')->andReturn('test_posts');
        $testPosts->shouldReceive('delete')->once()->andReturn(['taskUid' => 2]);

        $otherData = m::mock(Indexes::class);
        $otherData->shouldReceive('getUid')->andReturn('other_data');
        $otherData->shouldNotReceive('delete');

        $results = m::mock(IndexesResults::class);
        $results->shouldReceive('getResults')->andReturn([$testUsers, $testPosts, $otherData]);

        $client->shouldReceive('getIndexes')->once()->andReturn($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes('test_');
    }

    public function testDeleteAllIndexesWithNullPrefixDeletesEverything(): void
    {
        $client = m::mock(Client::class);

        $testUsers = m::mock(Indexes::class);
        $testUsers->shouldReceive('getUid')->andReturn('test_users');
        $testUsers->shouldReceive('delete')->once();

        $testPosts = m::mock(Indexes::class);
        $testPosts->shouldReceive('getUid')->andReturn('test_posts');
        $testPosts->shouldReceive('delete')->once();

        $otherData = m::mock(Indexes::class);
        $otherData->shouldReceive('getUid')->andReturn('other_data');
        $otherData->shouldReceive('delete')->once();

        $results = m::mock(IndexesResults::class);
        $results->shouldReceive('getResults')->andReturn([$testUsers, $testPosts, $otherData]);

        $client->shouldReceive('getIndexes')->once()->andReturn($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes(null);
    }

    public function testDeleteAllIndexesWithEmptyStringPrefixDeletesEverything(): void
    {
        $client = m::mock(Client::class);

        $testUsers = m::mock(Indexes::class);
        $testUsers->shouldReceive('getUid')->andReturn('test_users');
        $testUsers->shouldReceive('delete')->once();

        $testPosts = m::mock(Indexes::class);
        $testPosts->shouldReceive('getUid')->andReturn('test_posts');
        $testPosts->shouldReceive('delete')->once();

        $otherData = m::mock(Indexes::class);
        $otherData->shouldReceive('getUid')->andReturn('other_data');
        $otherData->shouldReceive('delete')->once();

        $results = m::mock(IndexesResults::class);
        $results->shouldReceive('getResults')->andReturn([$testUsers, $testPosts, $otherData]);

        $client->shouldReceive('getIndexes')->once()->andReturn($results);

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes('');
    }

    public function testDeleteAllIndexesReturnsTasks(): void
    {
        $client = m::mock(Client::class);

        $testUsers = m::mock(Indexes::class);
        $testUsers->shouldReceive('getUid')->andReturn('test_users');
        $testUsers->shouldReceive('delete')->andReturn(['taskUid' => 1]);

        $testPosts = m::mock(Indexes::class);
        $testPosts->shouldReceive('getUid')->andReturn('test_posts');
        $testPosts->shouldReceive('delete')->andReturn(['taskUid' => 2]);

        $results = m::mock(IndexesResults::class);
        $results->shouldReceive('getResults')->andReturn([$testUsers, $testPosts]);

        $client->shouldReceive('getIndexes')->andReturn($results);

        $engine = new MeilisearchEngine($client);
        $tasks = $engine->deleteAllIndexes('test_');

        $this->assertEquals([['taskUid' => 1], ['taskUid' => 2]], $tasks);
    }

    public function testUpdateIndexSettingsWithEmbedders(): void
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

    public function testUpdateIndexSettingsWithoutEmbedders(): void
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

    public function testConfigureSoftDeleteFilterAddsFilterableAttribute(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $settings = $engine->configureSoftDeleteFilter([
            'filterableAttributes' => ['status'],
        ]);

        $this->assertContains('__soft_deleted', $settings['filterableAttributes']);
        $this->assertContains('status', $settings['filterableAttributes']);
    }

    public function testEngineForwardsCallsToMeilisearchClient(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('health')
            ->once()
            ->andReturn(['status' => 'available']);

        $engine = new MeilisearchEngine($client);

        $result = $engine->health();

        $this->assertEquals(['status' => 'available'], $result);
    }

    public function testGetMeilisearchClientReturnsClient(): void
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $this->assertSame($client, $engine->getMeilisearchClient());
    }

    public function testGenerateTenantTokenUsesTheExplicitParentIdentityAndSigner(): void
    {
        $client = new Client('http://localhost:7700', 'different-client-key');
        $engine = new MeilisearchEngine($client);
        $expiresAt = new DateTimeImmutable('+1 hour');
        $apiKey = 'explicit-parent-key';

        $token = $engine->generateTenantToken(
            ['users' => ['filter' => 'tenant_id = 42']],
            'parent-key-uid',
            $apiKey,
            $expiresAt,
        );
        [$encodedHeader, $encodedPayload, $signature] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        $signedContent = $encodedHeader . '.' . $encodedPayload;
        $expectedSignature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedContent, $apiKey, true)
        ), '+/', '-_'), '=');
        $clientKeySignature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signedContent, 'different-client-key', true)
        ), '+/', '-_'), '=');

        $this->assertSame('parent-key-uid', $payload['apiKeyUid']);
        $this->assertSame(['users' => ['filter' => 'tenant_id = 42']], $payload['searchRules']);
        $this->assertSame($expiresAt->getTimestamp(), $payload['exp']);
        $this->assertSame($expectedSignature, $signature);
        $this->assertNotSame($clientKeySignature, $signature);
    }

    #[DataProvider('emptyTenantTokenCredentials')]
    public function testGenerateTenantTokenRejectsEmptyCredentials(
        string $apiKeyUid,
        string $apiKey,
        string $message
    ): void {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('generateTenantToken');
        $client->shouldNotReceive('getKeys');
        $engine = new MeilisearchEngine($client);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $engine->generateTenantToken(['users' => ['filter' => 'tenant_id = 42']], $apiKeyUid, $apiKey);
    }

    /**
     * Provide empty Meilisearch tenant-token credentials.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function emptyTenantTokenCredentials(): array
    {
        return [
            'empty UID' => ['', 'explicit-parent-key', 'Meilisearch tenant tokens require a non-empty API key UID.'],
            'empty key' => ['parent-key-uid', '', 'Meilisearch tenant tokens require a non-empty API key.'],
        ];
    }

    protected function apiException(int $status, string $code): ApiException
    {
        return new ApiException(new Response($status), [
            'message' => $code,
            'code' => $code,
        ]);
    }

    protected function createSearchableModelMock(): m\MockInterface
    {
        return m::mock(Model::class . ', ' . SearchableInterface::class);
    }

    protected function createSoftDeleteSearchableModelMock(): m\MockInterface
    {
        // Must mock a class that uses SoftDeletes for usesSoftDelete() to return true
        return m::mock(MeilisearchTestSoftDeleteModel::class . ', ' . SearchableInterface::class);
    }
}

/**
 * Test model for MeilisearchEngine tests.
 */
class MeilisearchTestSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected array $guarded = [];

    public bool $timestamps = false;
}

/**
 * Test model with soft deletes for MeilisearchEngine tests.
 */
class MeilisearchTestSoftDeleteModel extends Model implements SearchableInterface
{
    use Searchable;
    use SoftDeletes;

    protected array $guarded = [];

    public bool $timestamps = false;
}

class MeilisearchTestChirpModel extends Model
{
    protected ?string $table = 'chirps';

    protected array $guarded = [];

    public bool $timestamps = false;

    public function getScoutKeyName(): string
    {
        return 'scout_id';
    }

    public function indexableAs(): string
    {
        return $this->getTable();
    }
}

enum MeilisearchStringStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

enum MeilisearchIntegerPriority: int
{
    case Low = 2;
    case High = 1;
}
