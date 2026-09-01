<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\NullEngine;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Throwable;

class NullEngineTest extends TestCase
{
    public function testUpdateDoesNothing(): void
    {
        $engine = new NullEngine;
        $models = new EloquentCollection([m::mock(Model::class)]);

        // Should not throw any exception
        $engine->update($models);
        $this->assertTrue(true);
    }

    public function testDeleteDoesNothing(): void
    {
        $engine = new NullEngine;
        $models = new EloquentCollection([m::mock(Model::class)]);

        // Should not throw any exception
        $engine->delete($models);
        $this->assertTrue(true);
    }

    public function testSearchReturnsEmptyArray(): void
    {
        $engine = new NullEngine;
        $builder = m::mock(Builder::class);

        $result = $engine->search($builder);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testPaginateReturnsEmptyArray(): void
    {
        $engine = new NullEngine;
        $builder = m::mock(Builder::class);

        $result = $engine->paginate($builder, 15, 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMapIdsReturnsEmptyCollection(): void
    {
        $engine = new NullEngine;

        $result = $engine->mapIds([]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testMapReturnsEmptyEloquentCollection(): void
    {
        $engine = new NullEngine;
        $builder = m::mock(Builder::class);
        $model = m::mock(Model::class);

        $result = $engine->map($builder, [], $model);

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testLazyMapReturnsEmptyLazyCollection(): void
    {
        $engine = new NullEngine;
        $builder = m::mock(Builder::class);
        $model = m::mock(Model::class);

        $result = $engine->lazyMap($builder, [], $model);

        $this->assertInstanceOf(LazyCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testGetTotalCountReturnsZeroForEmptyResults(): void
    {
        $engine = new NullEngine;

        $this->assertSame(0, $engine->getTotalCount([]));
    }

    public function testGetTotalCountReturnsCountForCountableResults(): void
    {
        $engine = new NullEngine;

        $this->assertSame(3, $engine->getTotalCount([1, 2, 3]));
    }

    public function testFlushDoesNothing(): void
    {
        $engine = new NullEngine;
        $model = m::mock(Model::class);

        // Should not throw any exception
        $engine->flush($model);
        $this->assertTrue(true);
    }

    public function testCreateIndexReturnsEmptyArray(): void
    {
        $engine = new NullEngine;

        $result = $engine->createIndex('test-index');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeleteIndexReturnsEmptyArray(): void
    {
        $engine = new NullEngine;

        $result = $engine->deleteIndex('test-index');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeclinesObservationForEveryOperationEntryPoint(): void
    {
        $runner = new EngineOperationRunner;
        $observer = m::mock(EngineOperationObserver::class);
        $observer->shouldNotReceive('starting');
        $observer->shouldNotReceive('finished');
        $runner->observe($observer);
        $engine = (new NullEngine)->setOperationRunner($runner, 'null');
        $builder = m::mock(Builder::class);
        $model = m::mock(Model::class);
        $models = new EloquentCollection([$model]);

        $this->assertSame([], $engine->runSearch($builder));
        $this->assertSame([], $engine->runPaginate($builder, 15, 1));
        $engine->runUpdate($models);
        $engine->runDelete($models);
        $engine->runFlush($model);
        $this->assertSame(
            'result',
            $engine->runOperation('search', $builder, fn () => 'result')
        );
    }

    public function testSubclassWithRealOperationsCanOptIntoObservation(): void
    {
        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('searchableAs')->andReturn('models');
        $builder = new Builder($model, 'query');
        $runner = new EngineOperationRunner;
        $observer = new NullEngineOperationObserver;
        $runner->observe($observer);
        $engine = (new ObservableNullEngine)->setOperationRunner($runner, 'custom');

        $this->assertSame(['result'], $engine->runSearch($builder));
        $this->assertCount(1, $observer->operations);
        $this->assertSame('search', $observer->operations[0]->operation);
        $this->assertSame('custom', $observer->operations[0]->engineName);
    }
}

class ObservableNullEngine extends NullEngine
{
    protected function hasObservableOperations(): bool
    {
        return true;
    }

    public function search(Builder $builder): mixed
    {
        return ['result'];
    }
}

class NullEngineOperationObserver implements EngineOperationObserver
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
