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
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Throwable;

class EngineTest extends TestCase
{
    public function testOperationEntryPointsDescribeTheirExactTargets(): void
    {
        $model = $this->model();
        $builder = new Builder($model, 'query');
        $models = new EloquentCollection([$model, $model]);
        $runner = new EngineOperationRunner;
        $observer = new ScoutEngineOperationRecordingObserver;
        $runner->observe($observer);
        $engine = new ScoutEngineOperationEngine;

        $this->assertSame($engine, $engine->setOperationRunner($runner, 'fixture'));

        $this->assertSame('search-result', $engine->runSearch($builder));
        $this->assertSame('paginate-result', $engine->runPaginate($builder, 15, 2));
        $engine->runUpdate($models);
        $engine->runDelete(new EloquentCollection([$model]));
        $engine->runFlush($model);
        $this->assertSame(
            'filter-result',
            $engine->runOperation(
                'delete_by_filter',
                $builder,
                fn () => 'filter-result',
                index: 'write_override',
            )
        );

        $this->assertSame([
            ['search', 'read_index', null],
            ['paginate', 'read_index', null],
            ['update', 'write_index', 2],
            ['delete', 'write_index', 1],
            ['flush', 'write_index', null],
            ['delete_by_filter', 'write_override', null],
        ], array_map(
            fn (EngineOperation $operation): array => [
                $operation->operation,
                $operation->index,
                $operation->modelCount,
            ],
            $observer->operations,
        ));

        foreach ($observer->operations as $operation) {
            $this->assertSame('fixture', $operation->engineName);
            $this->assertSame($model::class, $operation->modelClass);
        }
    }

    public function testExplicitBuilderIndexOverridesSearchableIndex(): void
    {
        $model = $this->model();
        $builder = (new Builder($model, 'query'))->within('custom_read_index');
        $runner = new EngineOperationRunner;
        $observer = new ScoutEngineOperationRecordingObserver;
        $runner->observe($observer);
        $engine = (new ScoutEngineOperationEngine)->setOperationRunner($runner, 'fixture');

        $engine->runSearch($builder);
        $engine->runPaginate($builder, 15, 1);

        $this->assertSame('custom_read_index', $observer->operations[0]->index);
        $this->assertSame('custom_read_index', $observer->operations[1]->index);
    }

    public function testEmptyUpdateAndDeleteBypassObserversButStillCallEngine(): void
    {
        $runner = new EngineOperationRunner;
        $observer = new ScoutEngineOperationRecordingObserver;
        $runner->observe($observer);
        $engine = (new ScoutEngineOperationEngine)->setOperationRunner($runner, 'fixture');
        $models = new EloquentCollection;

        $engine->runUpdate($models);
        $engine->runDelete($models);

        $this->assertSame(['update', 'delete'], $engine->calls);
        $this->assertSame([], $observer->operations);
    }

    public function testEntryPointsCallEngineDirectlyWhenRunnerHasNoObservers(): void
    {
        $model = $this->model();
        $builder = new Builder($model, 'query');
        $engine = (new ScoutEngineOperationEngine)->setOperationRunner(
            new EngineOperationRunner,
            'fixture',
        );

        $this->assertSame('search-result', $engine->runSearch($builder));
        $this->assertSame('paginate-result', $engine->runPaginate($builder, 10, 1));
        $engine->runUpdate(new EloquentCollection([$model]));
        $engine->runDelete(new EloquentCollection([$model]));
        $engine->runFlush($model);
        $this->assertSame('operation-result', $engine->runOperation(
            'paginate',
            $builder,
            fn () => 'operation-result',
        ));

        $this->assertSame(['search', 'paginate', 'update', 'delete', 'flush'], $engine->calls);
    }

    public function testConvenienceMethodsObserveOnlySearchAndNotMapping(): void
    {
        $model = $this->model();
        $builder = new Builder($model, 'query');
        $runner = new EngineOperationRunner;
        $observer = new ScoutEngineOperationRecordingObserver;
        $runner->observe($observer);
        $engine = (new ScoutEngineOperationEngine)->setOperationRunner($runner, 'fixture');

        $engine->keys($builder);
        $engine->get($builder);
        $engine->cursor($builder);

        $this->assertSame(['search', 'search', 'search'], array_map(
            fn (EngineOperation $operation): string => $operation->operation,
            $observer->operations,
        ));
        $this->assertSame([
            'search',
            'mapIds',
            'search',
            'map',
            'search',
            'lazyMap',
        ], $engine->calls);
    }

    public function testLocalEnginesBypassNoOpWritesWhileObservingReads(): void
    {
        $model = $this->model();
        $builder = new Builder($model, 'query');
        $models = new EloquentCollection([$model]);

        foreach ([
            'database' => new ScoutEngineOperationDatabaseEngine,
            'collection' => new ScoutEngineOperationCollectionEngine,
        ] as $engineName => $engine) {
            $runner = new EngineOperationRunner;
            $observer = new ScoutEngineOperationRecordingObserver;
            $runner->observe($observer);
            $engine->setOperationRunner($runner, $engineName);

            $engine->runUpdate($models);
            $engine->runDelete($models);
            $engine->runFlush($model);
            $engine->runSearch($builder);

            $this->assertSame(['update', 'delete', 'flush', 'search'], $engine->calls);
            $this->assertCount(1, $observer->operations);
            $this->assertSame('search', $observer->operations[0]->operation);
            $this->assertSame($engineName, $observer->operations[0]->engineName);
        }
    }

    /**
     * Create a searchable model test double with different read and write indices.
     *
     * @return Model&SearchableInterface
     */
    protected function model(): Model
    {
        $model = m::mock(Model::class . ', ' . SearchableInterface::class);
        $model->shouldReceive('searchableAs')->andReturn('read_index');
        $model->shouldReceive('indexableAs')->andReturn('write_index');

        return $model;
    }
}

class ScoutEngineOperationRecordingObserver implements EngineOperationObserver
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

class ScoutEngineOperationEngine extends Engine
{
    /**
     * The invoked engine methods.
     *
     * @var array<string>
     */
    public array $calls = [];

    public function update(EloquentCollection $models): void
    {
        $this->calls[] = 'update';
    }

    public function delete(EloquentCollection $models): void
    {
        $this->calls[] = 'delete';
    }

    public function search(Builder $builder): mixed
    {
        $this->calls[] = 'search';

        return 'search-result';
    }

    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $this->calls[] = 'paginate';

        return 'paginate-result';
    }

    public function mapIds(mixed $results): Collection
    {
        $this->calls[] = 'mapIds';

        return new Collection;
    }

    public function map(Builder $builder, mixed $results, Model $model): EloquentCollection
    {
        $this->calls[] = 'map';

        return new EloquentCollection;
    }

    public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection
    {
        $this->calls[] = 'lazyMap';

        return new LazyCollection;
    }

    public function getTotalCount(mixed $results): int
    {
        return 0;
    }

    public function flush(Model $model): void
    {
        $this->calls[] = 'flush';
    }

    public function createIndex(string $name, array $options = []): mixed
    {
        return null;
    }

    public function deleteIndex(string $name): mixed
    {
        return null;
    }
}

class ScoutEngineOperationDatabaseEngine extends DatabaseEngine
{
    /**
     * The invoked engine methods.
     *
     * @var array<string>
     */
    public array $calls = [];

    public function update(EloquentCollection $models): void
    {
        $this->calls[] = 'update';
    }

    public function delete(EloquentCollection $models): void
    {
        $this->calls[] = 'delete';
    }

    public function flush(Model $model): void
    {
        $this->calls[] = 'flush';
    }

    public function search(Builder $builder): array
    {
        $this->calls[] = 'search';

        return ['results' => new EloquentCollection, 'total' => 0];
    }
}

class ScoutEngineOperationCollectionEngine extends CollectionEngine
{
    /**
     * The invoked engine methods.
     *
     * @var array<string>
     */
    public array $calls = [];

    public function update(EloquentCollection $models): void
    {
        $this->calls[] = 'update';
    }

    public function delete(EloquentCollection $models): void
    {
        $this->calls[] = 'delete';
    }

    public function flush(Model $model): void
    {
        $this->calls[] = 'flush';
    }

    public function search(Builder $builder): mixed
    {
        $this->calls[] = 'search';

        return 'search-result';
    }
}
