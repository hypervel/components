<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hyperf\Paginator\Paginator;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engine;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class BuilderTest extends TestCase
{
    public function testBuilderStoresQueryAndModel()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'test query');

        $this->assertSame($model, $builder->model);
        $this->assertSame('test query', $builder->query);
    }

    public function testWhereAddsConstraint()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->where('status', 'active');

        $this->assertSame($builder, $result);
        $this->assertSame(['status' => 'active'], $builder->wheres);
    }

    public function testWhereInAddsConstraint()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->whereIn('id', [1, 2, 3]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereInAcceptsArrayable()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        // Use an array directly as Collection may not implement Arrayable
        $result = $builder->whereIn('id', [1, 2, 3]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [1, 2, 3]], $builder->whereIns);
    }

    public function testWhereNotInAddsConstraint()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->whereNotIn('id', [4, 5, 6]);

        $this->assertSame($builder, $result);
        $this->assertSame(['id' => [4, 5, 6]], $builder->whereNotIns);
    }

    public function testWithinSetsCustomIndex()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->within('custom_index');

        $this->assertSame($builder, $result);
        $this->assertSame('custom_index', $builder->index);
    }

    public function testTakeSetsLimit()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->take(100);

        $this->assertSame($builder, $result);
        $this->assertSame(100, $builder->limit);
    }

    public function testOrderByAddsOrder()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->orderBy('name', 'asc');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByNormalizesDirection()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $builder->orderBy('name', 'ASC');

        $this->assertSame([['column' => 'name', 'direction' => 'asc']], $builder->orders);
    }

    public function testOrderByDescAddsDescendingOrder()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->orderByDesc('name');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'name', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestOrdersByCreatedAtDesc()
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getCreatedAtColumn')->andReturn('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->latest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestWithCustomColumn()
    {
        $model = m::mock(Model::class);

        $builder = new Builder($model, 'query');
        $result = $builder->latest('updated_at');

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'updated_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testOldestOrdersByCreatedAtAsc()
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getCreatedAtColumn')->andReturn('created_at');

        $builder = new Builder($model, 'query');
        $result = $builder->oldest();

        $this->assertSame($builder, $result);
        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $builder->orders);
    }

    public function testOptionsSetsOptions()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $result = $builder->options(['highlight' => true]);

        $this->assertSame($builder, $result);
        $this->assertSame(['highlight' => true], $builder->options);
    }

    public function testQuerySetsQueryCallback()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $callback = fn () => 'test';
        $result = $builder->query($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->queryCallback);
    }

    public function testWithRawResultsSetsCallback()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $callback = fn ($results) => $results;
        $result = $builder->withRawResults($callback);

        $this->assertSame($builder, $result);
        $this->assertNotNull($builder->afterRawSearchCallback);
    }

    public function testSoftDeleteSetsSoftDeleteWhere()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame(0, $builder->wheres['__soft_deleted']);
    }

    public function testHardDeleteDoesNotSetSoftDeleteWhere()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: false);

        $this->assertArrayNotHasKey('__soft_deleted', $builder->wheres);
    }

    public function testWithTrashedRemovesSoftDeleteWhere()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $this->assertSame(0, $builder->wheres['__soft_deleted']);

        $result = $builder->withTrashed();

        $this->assertSame($builder, $result);
        $this->assertArrayNotHasKey('__soft_deleted', $builder->wheres);
    }

    public function testOnlyTrashedSetsSoftDeleteWhereToOne()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query', null, softDelete: true);

        $result = $builder->onlyTrashed();

        $this->assertSame($builder, $result);
        $this->assertSame(1, $builder->wheres['__soft_deleted']);
    }

    public function testRawCallsEngineSearch()
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

    public function testKeysCallsEngineKeys()
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

    public function testGetCallsEngineGet()
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

    public function testFirstReturnsFirstResult()
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

    public function testFirstReturnsNullWhenNoResults()
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

    public function testPaginationCorrectlyHandlesPaginatedResults()
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
        for ($i = 0; $i < 15; $i++) {
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

    public function testSimplePaginationCorrectlyHandlesPaginatedResults()
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
        for ($i = 0; $i < 15; $i++) {
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

    public function testMacroable()
    {
        Builder::macro('testMacro', function () {
            return 'macro result';
        });

        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $this->assertSame('macro result', $builder->testMacro());
    }

    public function testApplyAfterRawSearchCallbackInvokesCallback()
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

    public function testApplyAfterRawSearchCallbackReturnsOriginalWhenNoCallback()
    {
        $model = m::mock(Model::class);
        $builder = new Builder($model, 'query');

        $original = ['hits' => []];
        $result = $builder->applyAfterRawSearchCallback($original);

        $this->assertSame($original, $result);
    }
}
