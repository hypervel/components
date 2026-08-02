<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\PaginatesEloquentModels;
use Hypervel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class BuilderTest extends TestCase
{
    public function testBuilderStoresQueryAndModel(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'test query');

        $this->assertSame($model, $builder->model);
        $this->assertSame('test query', $builder->query);
    }

    public function testWhereAddsConstraint(): void
    {
        $model = m::mock(Model::class);
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
        $model = m::mock(Model::class);
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
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->whereIn('id', [1, 2, 3]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereInAcceptsArrayable(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        // Use an array directly as Collection may not implement Arrayable
        $result = $builder->whereIn('id', [1, 2, 3]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereNotInAddsConstraint(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->whereNotIn('id', [4, 5, 6]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [4, 5, 6]], $builder->whereNotIns);
    }

    public function testWithinSetsCustomIndex(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->within('custom_index');

        $this->assertSame($builder, $result);
        $this->assertSame('custom_index', $builder->index);
    }

    public function testTakeSetsLimit(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->take(100);

        $this->assertSame($builder, $result);
        $this->assertSame(100, $builder->limit);
    }

    public function testOrderByAddsOrder(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->orderBy('name', 'asc');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByNormalizesDirection(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $builder->orderBy('name', 'ASC');

        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByDescAddsDescendingOrder(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->orderByDesc('name');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestOrdersByCreatedAtDesc(): void
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getCreatedAtColumn')->andReturn('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->latest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestWithCustomColumn(): void
    {
        $model = m::mock(Model::class);

        $builder = new Builder($model, 'query');
        $result = $builder->latest('updated_at');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'updated_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testOldestOrdersByCreatedAtAsc(): void
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getCreatedAtColumn')->andReturn('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->oldest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $builder->orders);
    }

    public function testOptionsSetsOptions(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->options(['highlight' => true]);

        $this->assertSame($builder, $result);
        $this->assertSame(['highlight' => true], $builder->options);
    }

    public function testQuerySetsQueryCallback(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $callback = fn () => 'test';
        $result = $builder->query($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->queryCallback);
    }

    public function testWithRawResultsSetsCallback(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $callback = fn ($results) => $results;
        $result = $builder->withRawResults($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->afterRawSearchCallback);
    }

    public function testSoftDeleteSetsSoftDeleteWhere(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame([[
            'field' => '__soft_deleted',
            'operator' => '=',
            'value' => 0,
        ]], $builder->wheres);
    }

    public function testHardDeleteDoesNotSetSoftDeleteWhere(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: false);

        $this->assertSame([], $builder->wheres);
    }

    public function testWithTrashedRemovesSoftDeleteWhere(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame('__soft_deleted', $builder->wheres[0]['field']);

        $result = $builder->withTrashed();

        $this->assertSame($builder, $result);
        $this->assertSame([], $builder->wheres);
    }

    public function testOnlyTrashedSetsSoftDeleteWhereToOne(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $result = $builder->onlyTrashed();

        $this->assertSame($builder, $result);
        $this->assertSame([[
            'field' => '__soft_deleted',
            'operator' => '=',
            'value' => 1,
        ]], $builder->wheres);
    }

    public function testRawCallsEngineSearch(): void
    {
        $model = m::mock(Model::class);
        $engine = m::mock(Engine::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $engine->shouldReceive('search')
            ->once()
            ->andReturn(['hits' => [], 'totalHits' => 0]);

        $builder = new Builder($model, 'query');

        $result = $builder->raw();

        $this->assertEquals(['hits' => [], 'totalHits' => 0], $result);
    }

    public function testKeysCallsEngineKeys(): void
    {
        $model = m::mock(Model::class);
        $engine = m::mock(Engine::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $engine->shouldReceive('keys')
            ->once()
            ->andReturn(new Collection([1, 2, 3]));

        $builder = new Builder($model, 'query');

        $result = $builder->keys();

        $this->assertEquals([1, 2, 3], $result->all());
    }

    public function testGetCallsEngineGet(): void
    {
        $model = m::mock(Model::class);
        $engine = m::mock(Engine::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $engine->shouldReceive('get')
            ->once()
            ->andReturn(new EloquentCollection([m::mock(Model::class)]));

        $builder = new Builder($model, 'query');

        $result = $builder->get();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(1, $result);
    }

    public function testFirstReturnsFirstResult(): void
    {
        $model = m::mock(Model::class);
        $engine = m::mock(Engine::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $firstModel = m::mock(Model::class);

        $engine->shouldReceive('get')
            ->once()
            ->andReturn(new EloquentCollection([$firstModel]));

        $builder = new Builder($model, 'query');

        $result = $builder->first();

        $this->assertSame($firstModel, $result);
    }

    public function testFirstReturnsNullWhenNoResults(): void
    {
        $model = m::mock(Model::class);
        $engine = m::mock(Engine::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $engine->shouldReceive('get')
            ->once()
            ->andReturn(new EloquentCollection([]));

        $builder = new Builder($model, 'query');

        $result = $builder->first();

        $this->assertNull($result);
    }

    public function testPaginationCorrectlyHandlesPaginatedResults(): void
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock(Engine::class));
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        // Create collection manually instead of using times()
        $items = [];
        for ($i = 0; $i < 15; ++$i) {
            $items[] = m::mock(Model::class);
        }
        $results = new EloquentCollection($items);

        $engine->shouldReceive('paginate')->once();
        $engine->shouldReceive('map')->andReturn($results);
        $engine->shouldReceive('getTotalCount')->andReturn(16);

        $model->shouldReceive('newCollection')
            ->with(m::type('array'))
            ->andReturn($results);

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

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock(Engine::class));

        // Create collection manually instead of using times()
        $items = [];
        for ($i = 0; $i < 15; ++$i) {
            $items[] = m::mock(Model::class);
        }
        $results = new EloquentCollection($items);

        $engine->shouldReceive('paginate')->once();
        $engine->shouldReceive('map')->andReturn($results);
        $engine->shouldReceive('getTotalCount')->andReturn(16);

        $model->shouldReceive('newCollection')
            ->with(m::type('array'))
            ->andReturn($results);

        $builder = new Builder($model, 'zonda');
        $paginated = $builder->simplePaginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
    }

    public function testPaginateDelegatesToEngineWhenImplementsPaginatesEloquentModels(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);

        // Create a mock engine that implements PaginatesEloquentModels
        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModels::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);

        // The engine's paginate method should be called directly
        $engine->shouldReceive('paginate')
            ->once()
            ->with(m::type(Builder::class), 15, 1)
            ->andReturn($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function testSimplePaginateDelegatesToEngineWhenImplementsPaginatesEloquentModels(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);

        // Create a mock engine that implements PaginatesEloquentModels
        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModels::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $expectedPaginator = new Paginator([], 15, 1);

        // The engine's simplePaginate method should be called directly
        $engine->shouldReceive('simplePaginate')
            ->once()
            ->with(m::type(Builder::class), 15, 1)
            ->andReturn($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->simplePaginate();

        $this->assertInstanceOf(Paginator::class, $result);
    }

    public function testPaginateDelegatesToEngineWhenImplementsPaginatesEloquentModelsUsingDatabase(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);

        // Create a mock engine that implements PaginatesEloquentModelsUsingDatabase
        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModelsUsingDatabase::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);

        // The engine's paginateUsingDatabase method should be called
        $engine->shouldReceive('paginateUsingDatabase')
            ->once()
            ->with(m::type(Builder::class), 15, 'page', 1)
            ->andReturn($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->paginate();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    public function testSimplePaginateDelegatesToEngineWhenImplementsPaginatesEloquentModelsUsingDatabase(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);

        // Create a mock engine that implements PaginatesEloquentModelsUsingDatabase
        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModelsUsingDatabase::class);
        $model->shouldReceive('searchableUsing')->andReturn($engine);

        $expectedPaginator = new Paginator([], 15, 1);

        // The engine's simplePaginateUsingDatabase method should be called
        $engine->shouldReceive('simplePaginateUsingDatabase')
            ->once()
            ->with(m::type(Builder::class), 15, 'page', 1)
            ->andReturn($expectedPaginator);

        $builder = new Builder($model, 'test query');
        $result = $builder->simplePaginate();

        $this->assertInstanceOf(Paginator::class, $result);
    }

    public function testRawPaginationDelegatesToEngineWhenItPaginatesEloquentModels(): void
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->twice()->andReturn(15);

        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModels::class);
        $model->shouldReceive('searchableUsing')->twice()->andReturn($engine);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);
        $expectedSimplePaginator = new Paginator([], 15, 1);

        $engine->shouldReceive('paginate')
            ->once()
            ->with(m::type(Builder::class), 15, 1)
            ->andReturn($expectedPaginator);
        $engine->shouldReceive('simplePaginate')
            ->once()
            ->with(m::type(Builder::class), 15, 1)
            ->andReturn($expectedSimplePaginator);

        $builder = new Builder($model, 'test query');

        $this->assertSame($expectedPaginator, $builder->paginateRaw(page: 1));
        $this->assertSame($expectedSimplePaginator, $builder->simplePaginateRaw(page: 1));
    }

    public function testRawPaginationDelegatesToEngineWhenItPaginatesUsingDatabase(): void
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->twice()->andReturn(15);

        $engine = m::mock(Engine::class . ', ' . PaginatesEloquentModelsUsingDatabase::class);
        $model->shouldReceive('searchableUsing')->twice()->andReturn($engine);

        $expectedPaginator = new LengthAwarePaginator([], 0, 15, 1);
        $expectedSimplePaginator = new Paginator([], 15, 1);

        $engine->shouldReceive('paginateUsingDatabase')
            ->once()
            ->with(m::type(Builder::class), 15, 'results', 1)
            ->andReturn($expectedPaginator);
        $engine->shouldReceive('simplePaginateUsingDatabase')
            ->once()
            ->with(m::type(Builder::class), 15, 'results', 1)
            ->andReturn($expectedSimplePaginator);

        $builder = new Builder($model, 'test query');

        $this->assertSame($expectedPaginator, $builder->paginateRaw(pageName: 'results', page: 1));
        $this->assertSame($expectedSimplePaginator, $builder->simplePaginateRaw(pageName: 'results', page: 1));
    }

    public function testGenericPaginationUsesFreshContainerSubstitutionsAndDefaultPerPageForZero(): void
    {
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => 'http://localhost/foo');

        Container::getInstance()->bind(Paginator::class, ScoutBuilderPaginator::class);
        Container::getInstance()->bind(LengthAwarePaginator::class, ScoutBuilderLengthAwarePaginator::class);

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->times(4)->andReturn(15);
        $model->shouldReceive('searchableUsing')->times(6)->andReturn($engine = m::mock(Engine::class));
        $model->shouldReceive('newCollection')->twice()->andReturnUsing(
            fn (array $models) => new EloquentCollection($models)
        );

        $rawResults = ['hits' => [], 'estimatedTotalHits' => 0];

        $engine->shouldReceive('paginate')->times(4)->andReturn($rawResults);
        $engine->shouldReceive('map')->twice()->andReturn(new EloquentCollection);
        $engine->shouldReceive('getTotalCount')->times(4)->andReturn(0);

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

    public function testMacroable(): void
    {
        Builder::macro('testMacro', function () {
            return 'macro result';
        });

        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $this->assertSame('macro result', $builder->testMacro());
    }

    public function testApplyAfterRawSearchCallbackInvokesCallback(): void
    {
        $model = m::mock(Model::class);
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
        $model = m::mock(Model::class);
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

        $model = m::mock(Model::class);
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock(Engine::class));

        $rawResults = ['hits' => [], 'estimatedTotalHits' => 16];

        $engine->shouldReceive('paginate')->once()->andReturn($rawResults);
        $engine->shouldReceive('getTotalCount')->andReturn(16);

        $builder = new Builder($model, 'zonda');
        $paginated = $builder->simplePaginateRaw();

        $this->assertSame($rawResults, $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
    }

    public function testWhereInAcceptsArrayableInterface(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        // Create a Collection (which implements Arrayable)
        $collection = new Collection([1, 2, 3]);

        $result = $builder->whereIn('id', $collection);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereNotInAcceptsArrayableInterface(): void
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        // Create a Collection (which implements Arrayable)
        $collection = new Collection([4, 5, 6]);

        $result = $builder->whereNotIn('id', $collection);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [4, 5, 6]], $builder->whereNotIns);
    }
}

class ScoutBuilderPaginator extends Paginator
{
}

class ScoutBuilderLengthAwarePaginator extends LengthAwarePaginator
{
}
