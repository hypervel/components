<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\Contracts\PaginatesEloquentModels;
use Hypervel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Scout;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class BuilderTest extends TestCase
{
    use VerifiesDoubles;

    public function testBuilderStoresQueryAndModel(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'test query');

        $this->assertSame($model, $builder->model);
        $this->assertSame('test query', $builder->query);
    }

    public function testBuilderIsNotPreparedUntilTerminalExecution(): void
    {
        $model = Double::for(Model::class)->strict();
        $calls = 0;

        Scout::prepareBuilderUsing(function () use (&$calls): void {
            ++$calls;
        });

        new Builder($model, 'test query');

        $this->assertSame(0, $calls);
    }

    public function testWhereAddsConstraint(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->where('status', 'active');

        $this->assertSame($builder, $result);
        $this->assertSame([[
            'field' => 'status',
            'operator' => '=',
            'value' => 'active',
        ]], $builder->wheres);
    }

    public function testWhereAddsComparisonConstraintWithoutOverwritingTheSameField(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder
            ->where('price', '>=', 10)
            ->where('price', '<', 20);

        $this->assertSame($builder, $result);
        $this->assertSame([
            ['field' => 'price', 'operator' => '>=', 'value' => 10],
            ['field' => 'price', 'operator' => '<', 'value' => 20],
        ], $builder->wheres);
    }

    public function testWhereInAddsConstraint(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->whereIn('id', [1, 2, 3]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereNotInAddsConstraint(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->whereNotIn('id', [4, 5, 6]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [4, 5, 6]], $builder->whereNotIns);
    }

    public function testWithinSetsCustomIndex(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->within('custom_index');

        $this->assertSame($builder, $result);
        $this->assertSame('custom_index', $builder->index);
    }

    public function testTakeSetsLimit(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->take(100);

        $this->assertSame($builder, $result);
        $this->assertSame(100, $builder->limit);
    }

    public function testOrderByAddsOrder(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->orderBy('name', 'asc');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByNormalizesDirection(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $builder->orderBy('name', 'ASC');

        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByDescAddsDescendingOrder(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->orderByDesc('name');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestOrdersByCreatedAtDesc(): void
    {
        $model = Double::for(Model::class)->strict();
        $model->allows('getCreatedAtColumn')->returns('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->latest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestWithCustomColumn(): void
    {
        $model = Double::for(Model::class)->strict();

        $builder = new Builder($model, 'query');
        $result = $builder->latest('updated_at');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'updated_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testOldestOrdersByCreatedAtAsc(): void
    {
        $model = Double::for(Model::class)->strict();
        $model->allows('getCreatedAtColumn')->returns('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->oldest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $builder->orders);
    }

    public function testOptionsSetsOptions(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $result = $builder->options(['highlight' => true]);

        $this->assertSame($builder, $result);
        $this->assertSame(['highlight' => true], $builder->options);
    }

    public function testQuerySetsQueryCallback(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $callback = fn () => 'test';
        $result = $builder->query($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->queryCallback);
    }

    public function testWithRawResultsSetsCallback(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $callback = fn ($results) => $results;
        $result = $builder->withRawResults($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->afterRawSearchCallback);
    }

    public function testSoftDeleteSetsSoftDeleteWhere(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame([[
            'field' => '__soft_deleted',
            'operator' => '=',
            'value' => 0,
        ]], $builder->wheres);
    }

    public function testHardDeleteDoesNotSetSoftDeleteWhere(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query', null, softDelete: false);

        $this->assertSame([], $builder->wheres);
    }

    public function testWithTrashedRemovesSoftDeleteWhere(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame('__soft_deleted', $builder->wheres[0]['field']);

        $result = $builder->withTrashed();

        $this->assertSame($builder, $result);
        $this->assertSame([], $builder->wheres);
    }

    public function testOnlyTrashedSetsSoftDeleteWhereToOne(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query', null, softDelete: true);

        $result = $builder->onlyTrashed();

        $this->assertSame($builder, $result);
        $this->assertSame([[
            'field' => '__soft_deleted',
            'operator' => '=',
            'value' => 1,
        ]], $builder->wheres);
    }

    public function testSemanticSearchCanBeEnabled(): void
    {
        $builder = (new Builder(Double::for(Model::class)->strict(), 'conceptual query'))->semantic(minSimilarity: 0.7);

        $this->assertTrue($builder->semanticSearch);
        $this->assertNull($builder->hybridSearch);
        $this->assertSame(0.7, $builder->minimumSimilarity);
    }

    public function testHybridSearchCanBeEnabledWithWeights(): void
    {
        $builder = (new Builder(Double::for(Model::class)->strict(), 'combined query'))->hybrid(2, 3, minSimilarity: 0.8);

        $this->assertFalse($builder->semanticSearch);
        $this->assertSame([
            'text_weight' => 2,
            'semantic_weight' => 3,
        ], $builder->hybridSearch);
        $this->assertSame(0.8, $builder->minimumSimilarity);
    }

    public function testSemanticAndHybridSearchRequireAQuery(): void
    {
        foreach (['semantic', 'hybrid'] as $method) {
            try {
                (new Builder(Double::for(Model::class)->strict(), ''))->{$method}();

                $this->fail("Expected [{$method}] to reject an empty query.");
            } catch (ScoutException $e) {
                $this->assertStringContainsString('non-empty query', $e->getMessage());
            }
        }
    }

    public function testHybridSearchRequiresPositiveWeights(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessageIsOrContains('positive numbers');

        (new Builder(Double::for(Model::class)->strict(), 'query'))->hybrid(1, 0);
    }

    public function testUnsupportedEnginesRejectSemanticSearch(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('searchableUsing')->returns(Double::for(Engine::class)->strict());

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageIsOrContains('does not support semantic search');

        (new Builder($model, 'query'))->semantic()->raw();
    }

    public function testUnsupportedEnginesRejectSemanticSearchEnabledDuringPreparation(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $engine->expects('runSearch')->never();

        Scout::prepareBuilderUsing(function (Builder $builder): void {
            $builder->semantic();
        });

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageIsOrContains('does not support semantic search');

        (new Builder($model, 'query'))->raw();
    }

    public function testUnsupportedEnginesTreatHybridSearchAsNormalTextSearch(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $engine->expects('runSearch')->returns(['results']);

        $results = (new Builder($model, 'query'))->hybrid()->raw();

        $this->assertSame(['results'], $results);
    }

    public function testRawCallsEngineSearchEntryPoint(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);

        $engine->expects('runSearch')
            ->returns(['hits' => [], 'totalHits' => 0]);

        $builder = new Builder($model, 'query');

        $result = $builder->raw();

        $this->assertEquals(['hits' => [], 'totalHits' => 0], $result);
    }

    public function testKeysCallsEngineKeys(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);

        $engine->expects('keys')
            ->returns(new Collection([1, 2, 3]));

        $builder = new Builder($model, 'query');

        $result = $builder->keys();

        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function testGetCallsEngineGet(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);

        $engine->expects('get')
            ->returns(new EloquentCollection([Double::for(Model::class)->strict()]));

        $builder = new Builder($model, 'query');

        $result = $builder->get();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(1, $result);
    }

    public function testFirstReturnsFirstResult(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);

        $firstModel = Double::for(Model::class)->strict();

        $engine->expects('get')
            ->returns(new EloquentCollection([$firstModel]));

        $builder = new Builder($model, 'query');

        $result = $builder->first();

        $this->assertSame($firstModel, $result);
    }

    public function testFirstReturnsNullWhenNoResults(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);

        $engine->expects('get')
            ->returns(new EloquentCollection([]));

        $builder = new Builder($model, 'query');

        $result = $builder->first();

        $this->assertNull($result);
    }

    public function testResultTerminalsPrepareBuilderExactlyOnce(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->expects('searchableUsing')->times(5)->returns($engine);
        $engine->expects('runSearch')->returns([]);
        $engine->expects('keys')->returns(new Collection);
        $engine->expects('get')->times(2)->returns(new EloquentCollection);
        $engine->expects('cursor')->returns(new LazyCollection([]));
        $builder = new Builder($model, 'query');
        $prepared = [];

        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use (
            $builder,
            $engine,
            &$prepared
        ): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            $prepared[] = true;
        });

        $builder->raw();
        $builder->keys();
        $builder->get();
        $builder->first();
        $builder->cursor();

        $this->assertCount(5, $prepared);
    }

    public function testPaginationTerminalsPrepareBeforeEngineExecution(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->expects('getPerPage')->times(4)->returns(15);
        $model->expects('searchableAs')->never();
        $engine = Double::for(BuilderTestPaginatingEngine::class)->strict();
        $model->expects('searchableUsing')->times(4)->returns($engine);
        $this->passThroughEngineOperations($engine, 4);
        $engine->expects('paginate')->times(2)->returns(new LengthAwarePaginator([], 0, 15, 1));
        $engine->expects('simplePaginate')->times(2)->returns(new Paginator([], 15, 1));
        $builder = new Builder($model, 'query');
        $prepared = 0;

        Scout::prepareBuilderUsing(function (Builder $givenBuilder, Engine $givenEngine) use (
            $builder,
            $engine,
            &$prepared
        ): void {
            $this->assertSame($builder, $givenBuilder);
            $this->assertSame($engine, $givenEngine);
            ++$prepared;
        });

        $builder->simplePaginate();
        $builder->paginate();
        $builder->paginateRaw();
        $builder->simplePaginateRaw();

        $this->assertSame(4, $prepared);
    }

    public function testPaginationCorrectlyHandlesPaginatedResults(): void
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->allows('searchableUsing')->returns($engine = Double::for(Engine::class)->strict());
        $model->allows('getScoutKeyName')->returns('id');

        // Create collection manually instead of using times()
        $items = [];
        for ($i = 0; $i < 15; ++$i) {
            $items[] = Double::for(Model::class)->strict();
        }
        $results = new EloquentCollection($items);

        $engine->expects('runPaginate');
        $engine->allows('map')->returns($results);
        $engine->allows('getTotalCount')->returns(16);

        $model->allows('newCollection')
            ->with(Argument::type('array'))
            ->returns($results);

        $builder = new Builder($model, 'zonda');
        $paginated = $builder->paginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertSame(16, $paginated->total());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
    }

    public function testSimplePaginationCorrectlyHandlesPaginatedResults(): void
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->allows('searchableUsing')->returns($engine = Double::for(Engine::class)->strict());

        // Create collection manually instead of using times()
        $items = [];
        for ($i = 0; $i < 15; ++$i) {
            $items[] = Double::for(Model::class)->strict();
        }
        $results = new EloquentCollection($items);

        $engine->expects('runPaginate');
        $engine->allows('map')->returns($results);
        $engine->allows('getTotalCount')->returns(16);

        $model->allows('newCollection')
            ->with(Argument::type('array'))
            ->returns($results);

        $builder = new Builder($model, 'zonda');
        $paginated = $builder->simplePaginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
    }

    public function testGenericPaginatorsShareTheTransformedPayloadWithMappingAndMetadata(): void
    {
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->expects('getPerPage')->times(4)->returns(15);
        $model->expects('searchableUsing')->times(8)->returns($engine);
        $model->expects('newCollection')->times(2)->resolves(
            fn (array $models) => new EloquentCollection($models)
        );

        $rawResults = ['hits' => ['raw'], 'estimatedTotalHits' => 30];
        $transformedResults = ['hits' => ['transformed'], 'estimatedTotalHits' => 16];
        $engine->expects('runPaginate')->times(4)->returns($rawResults);
        $engine->expects('map')
            ->times(2)
            ->with(Argument::all(fn (Builder $builder, array $results, Model $givenModel): bool => $results === $transformedResults
                && $givenModel === $model))
            ->returns(new EloquentCollection);
        $engine->expects('getTotalCount')
            ->times(4)
            ->with($transformedResults)
            ->returns(16);

        $callbackInvocations = 0;
        $builder = (new Builder($model, 'zonda'))->withRawResults(
            function () use (&$callbackInvocations, $transformedResults): array {
                ++$callbackInvocations;

                return $transformedResults;
            }
        );

        $this->assertTrue($builder->simplePaginate(page: 1)->hasMorePages());
        $this->assertSame(16, $builder->paginate(page: 1)->total());
        $this->assertSame($transformedResults, $builder->paginateRaw(page: 1)->items());
        $this->assertTrue($builder->simplePaginateRaw(page: 1)->hasMorePages());
        $this->assertSame(4, $callbackInvocations);
    }

    public function testSimplePaginatorsApplyTheEloquentQueryCallbackToTheirTotals(): void
    {
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $eloquentQuery = Double::for(EloquentBuilder::class)->strict();
        $baseQuery = Double::for(QueryBuilder::class)->strict();
        $rawResults = ['hits' => [], 'estimatedTotalHits' => 16];

        $model->expects('getPerPage')->times(2)->returns(15);
        $model->expects('searchableUsing')->times(4)->returns($engine);
        $model->expects('newCollection')->returns(new EloquentCollection);
        $model->expects('getScoutKeyName')->times(2)->returns('id');
        $model->expects('queryScoutModelsByIds')
            ->times(2)
            ->with(Argument::all(fn (Builder $builder, array $ids): bool => count($ids) === 16))
            ->returns($eloquentQuery);
        $eloquentQuery->expects('toBase')->times(2)->returns($baseQuery);
        $baseQuery->expects('getCountForPagination')->times(2)->returns(15);
        $engine->expects('runPaginate')->times(2)->returns($rawResults);
        $engine->expects('map')->returns(new EloquentCollection);
        $engine->expects('getTotalCount')->times(2)->returns(16);
        $engine->expects('mapIdsFrom')->times(2)->returns(new Collection(range(1, 16)));

        $builder = (new Builder($model, 'zonda'))->query(static function (): void {
        });

        $this->assertFalse($builder->simplePaginate(page: 1)->hasMorePages());
        $this->assertFalse($builder->simplePaginateRaw(page: 1)->hasMorePages());
    }

    public function testQueryCallbackTotalSearchIsObservedAfterPagination(): void
    {
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->passthru();
        $eloquentQuery = Double::for(EloquentBuilder::class)->strict();
        $baseQuery = Double::for(QueryBuilder::class)->strict();
        $rawResults = ['hits' => [], 'estimatedTotalHits' => 2];
        $runner = new EngineOperationRunner;
        $observer = Double::for(EngineOperationObserver::class)->strict();

        $observer->expects('starting')
            ->with(Argument::satisfies(fn (EngineOperation $operation): bool => $operation->operation === 'paginate'))
            ->ordered()
            ->returns('paginate');
        $observer->expects('finished')
            ->with(
                Argument::satisfies(fn (EngineOperation $operation): bool => $operation->operation === 'paginate'),
                'paginate',
                null,
            )
            ->ordered();
        $observer->expects('starting')
            ->with(Argument::satisfies(fn (EngineOperation $operation): bool => $operation->operation === 'search'))
            ->ordered()
            ->returns('search');
        $observer->expects('finished')
            ->with(
                Argument::satisfies(fn (EngineOperation $operation): bool => $operation->operation === 'search'),
                'search',
                null,
            )
            ->ordered();

        $runner->observe($observer);
        $engine->setOperationRunner($runner, 'fixture');

        $model->expects('getPerPage')->returns(15);
        $model->expects('searchableUsing')->times(2)->returns($engine);
        $model->expects('searchableAs')->times(2)->returns('read_index');
        $model->expects('newCollection')->returns(new EloquentCollection);
        $model->expects('getScoutKeyName')->returns('id');
        $model->expects('queryScoutModelsByIds')
            ->with(Argument::all(fn (Builder $builder, array $ids): bool => $ids === [1, 2]))
            ->returns($eloquentQuery);
        $eloquentQuery->expects('toBase')->returns($baseQuery);
        $baseQuery->expects('getCountForPagination')->returns(2);
        $engine->expects('paginate')->returns($rawResults);
        $engine->expects('map')->returns(new EloquentCollection);
        $engine->expects('getTotalCount')->returns(2);
        $engine->expects('mapIdsFrom')->returns(new Collection([1]));
        $engine->expects('search')->returns(['hits' => [1, 2]]);
        $engine->expects('mapIds')->returns(new Collection([1, 2]));

        $builder = (new Builder($model, 'zonda'))->query(static function (): void {
        });

        $this->assertSame(2, $builder->paginate(page: 1)->total());
    }

    public function testPaginateDelegatesToEngineWhenImplementsPaginatesEloquentModels(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->expects('searchableAs')->never();

        // Create a mock engine that implements PaginatesEloquentModels
        $engine = Double::for(BuilderTestPaginatingEngine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $this->passThroughEngineOperations($engine, 1);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);

        // The engine's paginate method should be called directly
        $engine->expects('paginate')
            ->with(Argument::type(Builder::class), 15, 1)
            ->returns($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function testSimplePaginateDelegatesToEngineWhenImplementsPaginatesEloquentModels(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->expects('searchableAs')->never();

        // Create a mock engine that implements PaginatesEloquentModels
        $engine = Double::for(BuilderTestPaginatingEngine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $this->passThroughEngineOperations($engine, 1);

        $expectedPaginator = new Paginator([], 15, 1);

        // The engine's simplePaginate method should be called directly
        $engine->expects('simplePaginate')
            ->with(Argument::type(Builder::class), 15, 1)
            ->returns($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->simplePaginate();

        $this->assertInstanceOf(Paginator::class, $result);
    }

    public function testPaginateDelegatesToEngineWhenImplementsPaginatesEloquentModelsUsingDatabase(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->expects('searchableAs')->never();

        // Create a mock engine that implements PaginatesEloquentModelsUsingDatabase
        $engine = Double::for(BuilderTestDatabasePaginatingEngine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $this->passThroughEngineOperations($engine, 1);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);

        // The engine's paginateUsingDatabase method should be called
        $engine->expects('paginateUsingDatabase')
            ->with(Argument::type(Builder::class), 15, 'page', 1)
            ->returns($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function testSimplePaginateDelegatesToEngineWhenImplementsPaginatesEloquentModelsUsingDatabase(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->expects('searchableAs')->never();

        // Create a mock engine that implements PaginatesEloquentModelsUsingDatabase
        $engine = Double::for(BuilderTestDatabasePaginatingEngine::class)->strict();
        $model->allows('searchableUsing')->returns($engine);
        $this->passThroughEngineOperations($engine, 1);

        $expectedPaginator = new Paginator([], 15, 1);

        // The engine's simplePaginateUsingDatabase method should be called
        $engine->expects('simplePaginateUsingDatabase')
            ->with(Argument::type(Builder::class), 15, 'page', 1)
            ->returns($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->simplePaginate();

        $this->assertInstanceOf(Paginator::class, $result);
    }

    public function testRawPaginationDelegatesToEngineWhenItPaginatesEloquentModels(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $model->expects('getPerPage')->times(2)->returns(15);
        $model->expects('searchableAs')->never();

        $engine = Double::for(BuilderTestPaginatingEngine::class)->strict();
        $model->expects('searchableUsing')->times(2)->returns($engine);
        $this->passThroughEngineOperations($engine, 2);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);
        $expectedSimplePaginator = new Paginator([], 15, 1);

        $engine->expects('paginate')
            ->with(Argument::type(Builder::class), 15, 1)
            ->returns($expectedPaginator);
        $engine->expects('simplePaginate')
            ->with(Argument::type(Builder::class), 15, 1)
            ->returns($expectedSimplePaginator);

        $builder = new Builder($model, 'test query');

        $this->assertSame($expectedPaginator, $builder->paginateRaw(page: 1));
        $this->assertSame($expectedSimplePaginator, $builder->simplePaginateRaw(page: 1));
    }

    public function testRawPaginationDelegatesToEngineWhenItPaginatesUsingDatabase(): void
    {
        $model = Double::for(SearchableModel::class)->strict();
        $model->expects('getPerPage')->times(2)->returns(15);
        $model->expects('searchableAs')->never();

        $engine = Double::for(BuilderTestDatabasePaginatingEngine::class)->strict();
        $model->expects('searchableUsing')->times(2)->returns($engine);
        $this->passThroughEngineOperations($engine, 2);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);
        $expectedSimplePaginator = new Paginator([], 15, 1);

        $engine->expects('paginateUsingDatabase')
            ->with(Argument::type(Builder::class), 15, 'results', 1)
            ->returns($expectedPaginator);
        $engine->expects('simplePaginateUsingDatabase')
            ->with(Argument::type(Builder::class), 15, 'results', 1)
            ->returns($expectedSimplePaginator);

        $builder = new Builder($model, 'test query');

        $this->assertSame($expectedPaginator, $builder->paginateRaw(pageName: 'results', page: 1));
        $this->assertSame($expectedSimplePaginator, $builder->simplePaginateRaw(pageName: 'results', page: 1));
    }

    public function testOptionalPaginationDoesNotResolveSearchIndexWithoutObservers(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $model->expects('getPerPage')->returns(15);
        $model->expects('searchableAs')->never();

        $engine = Double::for(DatabaseEngine::class)->passthru();
        $engine->setOperationRunner(new EngineOperationRunner, 'database');
        $model->expects('searchableUsing')->returns($engine);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);
        $engine->expects('paginateUsingDatabase')
            ->with(Argument::type(Builder::class), 15, 'page', 1)
            ->returns($expectedPaginator);

        $this->assertSame($expectedPaginator, (new Builder($model, 'query'))->paginate());
    }

    public function testGenericPaginationUsesFreshContainerSubstitutionsAndDefaultPerPageForZero(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        Container::getInstance()->bind(Paginator::class, ScoutBuilderPaginator::class);
        Container::getInstance()->bind(LengthAwarePaginator::class, ScoutBuilderLengthAwarePaginator::class);

        $model = Double::for(SearchableModel::class)->strict();
        $model->expects('getPerPage')->times(4)->returns(15);
        $model->expects('searchableUsing')->times(8)->returns($engine = Double::for(Engine::class)->strict());
        $model->expects('newCollection')->times(2)->resolves(
            fn (array $models) => new EloquentCollection($models)
        );

        $rawResults = ['hits' => [], 'estimatedTotalHits' => 0];

        $engine->expects('runPaginate')->times(4)->returns($rawResults);
        $engine->expects('map')->times(2)->returns(new EloquentCollection);
        $engine->expects('getTotalCount')->times(4)->returns(0);

        $builder = new Builder($model, 'zonda');

        $simple = $builder->simplePaginate(0);
        $simpleRaw = $builder->simplePaginateRaw(0);
        $lengthAware = $builder->paginate(0);
        $lengthAwareRaw = $builder->paginateRaw(0);

        $this->assertInstanceOf(ScoutBuilderPaginator::class, $simple);
        $this->assertInstanceOf(ScoutBuilderPaginator::class, $simpleRaw);
        $this->assertNotSame($simple, $simpleRaw);
        $this->assertSame(15, $simple->perPage());
        $this->assertSame(15, $simpleRaw->perPage());

        $this->assertInstanceOf(ScoutBuilderLengthAwarePaginator::class, $lengthAware);
        $this->assertInstanceOf(ScoutBuilderLengthAwarePaginator::class, $lengthAwareRaw);
        $this->assertNotSame($lengthAware, $lengthAwareRaw);
        $this->assertSame(15, $lengthAware->perPage());
        $this->assertSame(15, $lengthAwareRaw->perPage());
    }

    public function testPublicPaginationBoundariesClampInvalidPagesBeforeEngineDispatch(): void
    {
        Paginator::currentPageResolver(fn () => 3);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = Double::for(SearchableModel::class)->strict();
        $engine = Double::for(Engine::class)->strict();
        $model->expects('getPerPage')->times(5)->returns(15);
        $model->expects('searchableUsing')->times(10)->returns($engine);
        $model->expects('newCollection')->times(2)->returns(new EloquentCollection);

        $pages = [];
        $rawResults = ['hits' => [], 'estimatedTotalHits' => 0];
        $engine->expects('runPaginate')->times(5)->resolves(
            function (Builder $_, int $perPage, int $page) use (&$pages, $rawResults): array {
                $this->assertSame(15, $perPage);
                $pages[] = $page;

                return $rawResults;
            }
        );
        $engine->expects('map')->times(2)->returns(new EloquentCollection);
        $engine->expects('getTotalCount')->times(5)->returns(0);

        $builder = new Builder($model, 'query');

        $this->assertSame(1, $builder->simplePaginate(page: 0)->currentPage());
        $this->assertSame(1, $builder->paginate(page: -2)->currentPage());
        $this->assertSame(1, $builder->paginateRaw(page: 0)->currentPage());
        $this->assertSame(1, $builder->simplePaginateRaw(page: -2)->currentPage());
        $this->assertSame(3, $builder->paginateRaw()->currentPage());
        $this->assertSame([1, 1, 1, 1, 3], $pages);
    }

    public function testMacroable(): void
    {
        Builder::macro('testMacro', function () {
            return 'macro result';
        });

        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $this->assertSame('macro result', $builder->testMacro());
    }

    public function testApplyAfterRawSearchCallbackInvokesCallback(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $builder->withRawResults(function ($results) {
            $results['modified'] = true;
            return $results;
        });

        $result = $builder->applyAfterRawSearchCallback(['hits' => []]);

        $this->assertTrue($result['modified']);
    }

    public function testApplyAfterRawSearchCallbackReturnsOriginalWhenNoCallback(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        $original = ['hits' => []];
        $result = $builder->applyAfterRawSearchCallback($original);

        $this->assertSame($original, $result);
    }

    public function testSimplePaginateRawCorrectlyHandlesPaginatedResults(): void
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $model = Double::for(SearchableModel::class)->strict();
        $model->allows('getPerPage')->returns(15);
        $model->allows('searchableUsing')->returns($engine = Double::for(Engine::class)->strict());

        $rawResults = ['hits' => [], 'estimatedTotalHits' => 16];

        $engine->expects('runPaginate')->returns($rawResults);
        $engine->allows('getTotalCount')->returns(16);

        $builder = new Builder($model, 'zonda');
        $paginated = $builder->simplePaginateRaw();

        $this->assertSame($rawResults, $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
    }

    public function testWhereInAcceptsArrayableInterface(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        // Create a Collection (which implements Arrayable)
        $collection = new Collection([1, 2, 3]);

        $result = $builder->whereIn('id', $collection);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereNotInAcceptsArrayableInterface(): void
    {
        $model = Double::for(Model::class)->strict();
        $builder = new Builder($model, 'query');

        // Create a Collection (which implements Arrayable)
        $collection = new Collection([4, 5, 6]);

        $result = $builder->whereNotIn('id', $collection);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [4, 5, 6]], $builder->whereNotIns);
    }

    /**
     * Let doubled optional engine operations execute their narrowed callback.
     */
    protected function passThroughEngineOperations(Engine&DoubleInterface $engine, int $times): void
    {
        $engine->expects('runOperation')
            ->times($times)
            ->with(
                'paginate',
                Argument::type(Builder::class),
                Argument::type(Closure::class),
            )
            ->resolves(
                fn (string $operation, Builder $builder, Closure $callback): mixed => $callback()
            );
    }
}

class ScoutBuilderPaginator extends Paginator
{
}

class ScoutBuilderLengthAwarePaginator extends LengthAwarePaginator
{
}

abstract class BuilderTestPaginatingEngine extends Engine implements PaginatesEloquentModels
{
    /**
     * Paginate the given search on the engine.
     */
    abstract public function paginate(Builder $builder, int $perPage, int $page): LengthAwarePaginatorContract;
}

abstract class BuilderTestDatabasePaginatingEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
{
}
