<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Engines;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engines\NullEngine;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class NullEngineTest extends TestCase
{
    public function testUpdateDoesNothing()
    {
        $engine = new NullEngine();
        $models = new EloquentCollection([m::mock(Model::class)]);

        // Should not throw any exception
        $engine->update($models);
        $this->assertTrue(true);
    }

    public function testDeleteDoesNothing()
    {
        $engine = new NullEngine();
        $models = new EloquentCollection([m::mock(Model::class)]);

        // Should not throw any exception
        $engine->delete($models);
        $this->assertTrue(true);
    }

    public function testSearchReturnsEmptyArray()
    {
        $engine = new NullEngine();
        $builder = m::mock(Builder::class);

        $result = $engine->search($builder);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testPaginateReturnsEmptyArray()
    {
        $engine = new NullEngine();
        $builder = m::mock(Builder::class);

        $result = $engine->paginate($builder, 15, 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMapIdsReturnsEmptyCollection()
    {
        $engine = new NullEngine();

        $result = $engine->mapIds([]);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testMapReturnsEmptyEloquentCollection()
    {
        $engine = new NullEngine();
        $builder = m::mock(Builder::class);
        $model = m::mock(Model::class);

        $result = $engine->map($builder, [], $model);

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testLazyMapReturnsEmptyLazyCollection()
    {
        $engine = new NullEngine();
        $builder = m::mock(Builder::class);
        $model = m::mock(Model::class);

        $result = $engine->lazyMap($builder, [], $model);

        $this->assertInstanceOf(LazyCollection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testGetTotalCountReturnsZeroForEmptyResults()
    {
        $engine = new NullEngine();

        $this->assertSame(0, $engine->getTotalCount([]));
    }

    public function testGetTotalCountReturnsCountForCountableResults()
    {
        $engine = new NullEngine();

        $this->assertSame(3, $engine->getTotalCount([1, 2, 3]));
    }

    public function testFlushDoesNothing()
    {
        $engine = new NullEngine();
        $model = m::mock(Model::class);

        // Should not throw any exception
        $engine->flush($model);
        $this->assertTrue(true);
    }

    public function testCreateIndexReturnsEmptyArray()
    {
        $engine = new NullEngine();

        $result = $engine->createIndex('test-index');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeleteIndexReturnsEmptyArray()
    {
        $engine = new NullEngine();

        $result = $engine->deleteIndex('test-index');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
