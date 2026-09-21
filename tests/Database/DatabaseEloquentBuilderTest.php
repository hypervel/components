<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBuilderTest;

use BadMethodCallException;
use Closure;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Database\BinaryParameter;
use Hypervel\Database\ClassMorphViolationException;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\RelationNotFoundException;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection as BaseCollection;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;

class DatabaseEloquentBuilderTest extends TestCase
{
    public function testFindMethod(): void
    {
        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $builder->setModel($model);
        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $expectedModel = m::mock(Model::class);
        $builder->expects('first')->with(['column'])->andReturn($expectedModel);

        $result = $builder->find('bar', ['column']);
        $this->assertSame($expectedModel, $result);
    }

    public function testFindSoleMethod(): void
    {
        $builder = m::mock(Builder::class . '[sole]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $builder->setModel($model);
        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $expectedModel = m::mock(Model::class);
        $builder->expects('sole')->with(['column'])->andReturn($expectedModel);

        $result = $builder->findSole('bar', ['column']);
        $this->assertSame($expectedModel, $result);
    }

    public function testFindManyMethod(): void
    {
        // ids are not empty
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder->setModel($model);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', ['one', 'two']);
        $expectedCollection = new Collection(['baz']);
        $builder->expects('get')->with(['column'])->andReturn($expectedCollection);

        $result = $builder->findMany(['one', 'two'], ['column']);
        $this->assertEquals($expectedCollection, $result);

        // ids are empty array
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $emptyCollection = new Collection;
        $model->expects('newCollection')->withNoArgs()->andReturn($emptyCollection);
        $model->shouldReceive('getKeyType')->andReturn('int');
        $builder->setModel($model);
        $builder->getQuery()->shouldNotReceive('whereIntegerInRaw');
        $builder->shouldNotReceive('get');

        $result = $builder->findMany([], ['column']);
        $this->assertSame($emptyCollection, $result);

        // ids are empty collection
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $emptyCollection2 = new Collection;
        $model->expects('newCollection')->withNoArgs()->andReturn($emptyCollection2);
        $builder->setModel($model);
        $builder->getQuery()->shouldNotReceive('whereIn');
        $builder->shouldNotReceive('get');

        $result = $builder->findMany(collect(), ['column']);
        $this->assertSame($emptyCollection2, $result);
    }

    public function testFindOrNewMethodModelFound(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $expectedModel = m::mock(Model::class);
        $model->expects('findOrNew')->andReturn($expectedModel);

        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($model);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $builder->expects('first')->with(['column'])->andReturn($expectedModel);

        $expected = $model->findOrNew('bar', ['column']);
        $result = $builder->find('bar', ['column']);
        $this->assertEquals($expected, $result);
    }

    public function testFindOrNewMethodModelNotFound(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $model->expects('findOrNew')->andReturn(m::mock(Model::class));

        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($model);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $builder->expects('first')->with(['column'])->andReturn(null);

        $result = $model->findOrNew('bar', ['column']);
        $findResult = $builder->find('bar', ['column']);
        $this->assertNull($findResult);
        $this->assertInstanceOf(Model::class, $result);
    }

    public function testFindOrFailMethodThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder->setModel($model);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $builder->expects('first')->with(['column'])->andReturn(null);
        $builder->findOrFail('bar', ['column']);
    }

    public function testFindOrFailMethodThrowsModelNotFoundExceptionWithBackedEnum(): void
    {
        $exception = new ModelNotFoundException;
        $exception->setModel('Foo', BuilderTestBackedEnum::Bar);

        $this->assertSame('No query results for model [Foo] bar', $exception->getMessage());
        $this->assertSame(['bar'], $exception->getIds());
    }

    public function testFindOrFailMethodThrowsModelNotFoundExceptionWithUnitEnum(): void
    {
        $exception = new ModelNotFoundException;
        $exception->setModel('Foo', BuilderTestUnitEnum::Baz);

        $this->assertSame('No query results for model [Foo] Baz', $exception->getMessage());
        $this->assertSame(['Baz'], $exception->getIds());
    }

    public function testFindOrFailMethodWithManyThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $model = $this->getMockModel();
        $model->expects('getKey')->andReturn(1);
        $model->expects('getKeyType')->andReturn('int');

        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $builder->setModel($model);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2]);
        $builder->expects('get')->with(['column'])->andReturn(new Collection([$model]));
        $builder->findOrFail([1, 2], ['column']);
    }

    public function testFindOrFailMethodWithManyUsingCollectionThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $model = $this->getMockModel();
        $model->expects('getKey')->andReturn(1);
        $model->expects('getKeyType')->andReturn('int');

        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $builder->setModel($model);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2]);
        $builder->expects('get')->with(['column'])->andReturn(new Collection([$model]));
        $builder->findOrFail(new Collection([1, 2]), ['column']);
    }

    public function testFindOrMethod(): void
    {
        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->times(3)->andReturn('int');
        $builder->setModel($model);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 1)->times(2);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 2);
        $builder->expects('first')->andReturn($model);
        $builder->expects('first')->with(['column'])->andReturn($model);
        $builder->expects('first')->andReturn(null);

        $this->assertSame($model, $builder->findOr(1, fn (): string => 'callback result'));
        $this->assertSame($model, $builder->findOr(1, ['column'], fn (): string => 'callback result'));
        $this->assertSame('callback result', $builder->findOr(2, fn (): string => 'callback result'));
    }

    public function testFindOrMethodWithMany(): void
    {
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model1 = $this->getMockModel();
        $model2 = $this->getMockModel();
        $model1->expects('getKeyType')->times(3)->andReturn('int');
        $model2->shouldReceive('getKeyType')->andReturn('int');
        $builder->setModel($model1);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2])->times(2);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2, 3]);
        $builder->expects('get')->andReturn(new Collection([$model1, $model2]));
        $builder->expects('get')->with(['column'])->andReturn(new Collection([$model1, $model2]));
        // Multiple IDs return a collection, so an empty result does not invoke the callback.
        $builder->expects('get')->andReturn(new Collection);

        $result = $builder->findOr([1, 2], fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame($model1, $result[0]);
        $this->assertSame($model2, $result[1]);

        $result = $builder->findOr([1, 2], ['column'], fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame($model1, $result[0]);
        $this->assertSame($model2, $result[1]);

        // When no models found, still returns empty Collection (not callback result)
        $result = $builder->findOr([1, 2, 3], fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function testFindOrMethodWithManyUsingCollection(): void
    {
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model1 = $this->getMockModel();
        $model2 = $this->getMockModel();
        $model1->expects('getKeyType')->times(3)->andReturn('int');
        $model2->shouldReceive('getKeyType')->andReturn('int');
        $builder->setModel($model1);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2])->times(2);
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2, 3]);
        $builder->expects('get')->andReturn(new Collection([$model1, $model2]));
        $builder->expects('get')->with(['column'])->andReturn(new Collection([$model1, $model2]));
        // Multiple IDs return a collection, so an empty result does not invoke the callback.
        $builder->expects('get')->andReturn(new Collection);

        $result = $builder->findOr(new Collection([1, 2]), fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame($model1, $result[0]);
        $this->assertSame($model2, $result[1]);

        $result = $builder->findOr(new Collection([1, 2]), ['column'], fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame($model1, $result[0]);
        $this->assertSame($model2, $result[1]);

        // When no models found, still returns empty Collection (not callback result)
        $result = $builder->findOr(new Collection([1, 2, 3]), fn (): string => 'callback result');
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function testFirstOrFailMethodThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $builder->setModel($this->getMockModel());
        $builder->expects('first')->with(['column'])->andReturn(null);
        $builder->firstOrFail(['column']);
    }

    public function testFindWithMany(): void
    {
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2]);
        $builder->setModel($model);
        $expectedCollection = new Collection(['baz']);
        $builder->expects('get')->with(['column'])->andReturn($expectedCollection);

        $result = $builder->find([1, 2], ['column']);
        $this->assertSame($expectedCollection, $result);
    }

    public function testFindWithManyUsingCollection(): void
    {
        $ids = collect([1, 2]);
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('whereIntegerInRaw')->with('foo_table.foo', [1, 2]);
        $builder->setModel($model);
        $expectedCollection = new Collection(['baz']);
        $builder->expects('get')->with(['column'])->andReturn($expectedCollection);

        $result = $builder->find($ids, ['column']);
        $this->assertSame($expectedCollection, $result);
    }

    public function testFirstMethod(): void
    {
        $builder = m::mock(Builder::class . '[get,take]', [$this->getMockQueryBuilder()]);
        $builder->expects('limit')->with(1)->andReturnSelf();
        $builder->expects('get')->with(['*'])->andReturn(new Collection(['bar']));

        $result = $builder->first();
        $this->assertSame('bar', $result);
    }

    public function testQualifyColumn(): void
    {
        $builder = new Builder(m::mock(BaseBuilder::class));
        $builder->expects('from')->with('foo_table');

        $builder->setModel(new StubStringPrimaryKey);

        $this->assertSame('foo_table.column', $builder->qualifyColumn('column'));
    }

    public function testQualifyColumns(): void
    {
        $builder = new Builder(m::mock(BaseBuilder::class));
        $builder->expects('from')->with('foo_table');

        $builder->setModel(new StubStringPrimaryKey);

        $this->assertEquals(['foo_table.column', 'foo_table.name'], $builder->qualifyColumns(['column', 'name']));
    }

    public function testGetMethodLoadsModelsAndHydratesEagerRelations(): void
    {
        $builder = m::mock(Builder::class . '[getModels,eagerLoadRelations]', [$this->getMockQueryBuilder()]);
        $builder->expects('getModels')->with(['foo'])->andReturn(['bar']);
        $builder->expects('eagerLoadRelations')->with(['bar'])->andReturn(['bar', 'baz']);
        $builder->setModel($this->getMockModel());
        $builder->getModel()->expects('newCollection')->with(['bar', 'baz'])->andReturn(new Collection(['bar', 'baz']));

        $results = $builder->get(['foo']);
        $this->assertEquals(['bar', 'baz'], $results->all());
    }

    public function testGetMethodDoesntHydrateEagerRelationsWhenNoResultsAreReturned(): void
    {
        $builder = m::mock(Builder::class . '[getModels,eagerLoadRelations]', [$this->getMockQueryBuilder()]);
        $builder->expects('getModels')->with(['foo'])->andReturn([]);
        $builder->shouldReceive('eagerLoadRelations')->never();
        $builder->setModel($this->getMockModel());
        $builder->getModel()->expects('newCollection')->with([])->andReturn(new Collection([]));

        $results = $builder->get(['foo']);
        $this->assertSame([], $results->all());
    }

    public function testValueMethodWithModelFound(): void
    {
        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $mockModel = new class extends Model {};
        $mockModel->name = 'foo';
        $builder->expects('first')->with(['name'])->andReturn($mockModel);

        $this->assertSame('foo', $builder->value('name'));
    }

    public function testValueMethodWithModelNotFound(): void
    {
        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $builder->expects('first')->with(['name'])->andReturn(null);

        $this->assertNull($builder->value('name'));
    }

    public function testValueOrFailMethodWithModelFound(): void
    {
        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $mockModel = m::mock(Model::class)->makePartial();
        $mockModel->forceFill(['name' => 'foo']);
        $builder->expects('first')->with(['name'])->andReturn($mockModel);

        $this->assertSame('foo', $builder->valueOrFail('name'));
    }

    public function testValueOrFailMethodWithModelNotFoundThrowsModelNotFoundException(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $builder = m::mock(Builder::class . '[first]', [$this->getMockQueryBuilder()]);
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder->setModel($model);
        $builder->getQuery()->expects('where')->with('foo_table.foo', '=', 'bar');
        $builder->expects('first')->with(['column'])->andReturn(null);
        $builder->whereKey('bar')->valueOrFail('column');
    }

    public function testChunkWithLastChunkComplete(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection(['foo1', 'foo2']);
        $chunk2 = new Collection(['foo3', 'foo4']);
        $chunk3 = new Collection([]);

        $builder->expects('getOffset')->andReturn(null);
        $builder->expects('getLimit')->andReturn(null);
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('offset')->with(2)->andReturnSelf();
        $builder->expects('offset')->with(4)->andReturnSelf();
        $builder->expects('limit')->times(3)->with(2)->andReturnSelf();
        $builder->expects('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->expects('doSomething')->with($chunk1);
        $callbackAssertor->expects('doSomething')->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunk(2, function (Collection $results) use ($callbackAssertor): void {
            $callbackAssertor->doSomething($results);
        });
    }

    public function testChunkWithLastChunkPartial(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection(['foo1', 'foo2']);
        $chunk2 = new Collection(['foo3']);
        $builder->expects('getOffset')->andReturn(null);
        $builder->expects('getLimit')->andReturn(null);
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('offset')->with(2)->andReturnSelf();
        $builder->expects('limit')->times(2)->with(2)->andReturnSelf();
        $builder->expects('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->expects('doSomething')->with($chunk1);
        $callbackAssertor->expects('doSomething')->with($chunk2);

        $builder->chunk(2, function (Collection $results) use ($callbackAssertor): void {
            $callbackAssertor->doSomething($results);
        });
    }

    public function testChunkCanBeStoppedByReturningFalse(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection(['foo1', 'foo2']);
        $chunk2 = new Collection(['foo3']);

        $builder->expects('getOffset')->andReturn(null);
        $builder->expects('getLimit')->andReturn(null);
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('limit')->with(2)->andReturnSelf();
        $builder->expects('get')->andReturn($chunk1);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->expects('doSomething')->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk2);

        $builder->chunk(2, function (Collection $results) use ($callbackAssertor): bool {
            $callbackAssertor->doSomething($results);

            return false;
        });
    }

    public function testChunkWithCountZero(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->shouldReceive('getOffset')->never();
        $builder->shouldReceive('getLimit')->never();
        $builder->shouldReceive('offset')->never();
        $builder->shouldReceive('limit')->never();
        $builder->shouldReceive('get')->never();

        foreach ([0, -1] as $count) {
            try {
                $builder->chunk($count, function (): never {
                    $this->fail('Should not be called.');
                });
                $this->fail('The nonpositive chunk size was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The chunk size should be at least 1', $exception->getMessage());
            }
        }
    }

    public function testChunkPaginatesUsingIdWithLastChunkComplete(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = new Collection([(object) ['someIdField' => 10], (object) ['someIdField' => 11]]);
        $chunk3 = new Collection([]);
        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('forPageAfterId')->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 11, 'someIdField')->andReturnSelf();
        $builder->expects('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->expects('doSomething')->with($chunk1);
        $callbackAssertor->expects('doSomething')->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunkById(2, function (Collection $results) use ($callbackAssertor): void {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithLastChunkPartial(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = new Collection([(object) ['someIdField' => 10]]);
        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('forPageAfterId')->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->expects('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->expects('doSomething')->with($chunk1);
        $callbackAssertor->expects('doSomething')->with($chunk2);

        $builder->chunkById(2, function (Collection $results) use ($callbackAssertor): void {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    public function testChunkPaginatesUsingIdWithCountZero(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->shouldReceive('getOffset')->never();
        $builder->shouldReceive('getLimit')->never();
        $builder->shouldReceive('forPageAfterId')->never();
        $builder->shouldReceive('get')->never();

        foreach ([0, -1] as $count) {
            try {
                $builder->chunkById($count, function (): never {
                    $this->fail('Should never be called.');
                }, 'someIdField');
                $this->fail('The nonpositive chunk size was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The chunk size should be at least 1', $exception->getMessage());
            }
        }
    }

    public function testLazyWithLastChunkComplete(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('offset')->with(2)->andReturnSelf();
        $builder->expects('offset')->with(4)->andReturnSelf();
        $builder->expects('limit')->times(3)->with(2)->andReturnSelf();
        $builder->expects('get')->times(3)->andReturn(
            new Collection(['foo1', 'foo2']),
            new Collection(['foo3', 'foo4']),
            new Collection([])
        );

        $this->assertEquals(
            ['foo1', 'foo2', 'foo3', 'foo4'],
            $builder->lazy(2)->all()
        );
    }

    public function testLazyWithLastChunkPartial(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('offset')->with(2)->andReturnSelf();
        $builder->expects('limit')->twice()->with(2)->andReturnSelf();
        $builder->expects('get')->times(2)->andReturn(
            new Collection(['foo1', 'foo2']),
            new Collection(['foo3'])
        );

        $this->assertEquals(
            ['foo1', 'foo2', 'foo3'],
            $builder->lazy(2)->all()
        );
    }

    public function testLazyIsLazy(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,offset,limit,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('offset')->with(0)->andReturnSelf();
        $builder->expects('limit')->with(2)->andReturnSelf();
        $builder->expects('get')->andReturn(new Collection(['foo1', 'foo2']));

        $this->assertEquals(['foo1', 'foo2'], $builder->lazy(2)->take(2)->all());
    }

    public function testLazyByIdWithLastChunkComplete(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = new Collection([(object) ['someIdField' => 10], (object) ['someIdField' => 11]]);
        $chunk3 = new Collection([]);
        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('forPageAfterId')->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 11, 'someIdField')->andReturnSelf();
        $builder->expects('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $this->assertEquals(
            [
                (object) ['someIdField' => 1],
                (object) ['someIdField' => 2],
                (object) ['someIdField' => 10],
                (object) ['someIdField' => 11],
            ],
            $builder->lazyById(2, 'someIdField')->all()
        );
    }

    public function testLazyByIdWithLastChunkPartial(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = new Collection([(object) ['someIdField' => 10]]);
        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('forPageAfterId')->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->expects('forPageAfterId')->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->expects('get')->times(2)->andReturn($chunk1, $chunk2);

        $this->assertEquals(
            [
                (object) ['someIdField' => 1],
                (object) ['someIdField' => 2],
                (object) ['someIdField' => 10],
            ],
            $builder->lazyById(2, 'someIdField')->all()
        );
    }

    public function testLazyByIdIsLazy(): void
    {
        $builder = m::mock(Builder::class . '[getOffset,getLimit,forPageAfterId,get]', [$this->getMockQueryBuilder()]);
        $builder->getQuery()->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = new Collection([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $builder->expects('getOffset')->andReturnNull();
        $builder->expects('getLimit')->andReturnNull();
        $builder->expects('forPageAfterId')->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->expects('get')->andReturn($chunk1);

        $this->assertEquals(
            [
                (object) ['someIdField' => 1],
                (object) ['someIdField' => 2],
            ],
            $builder->lazyById(2, 'someIdField')->take(2)->all()
        );
    }

    public function testPluckReturnsTheMutatedAttributesOfAModel(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with('name', '')->andReturn(new BaseCollection(['bar', 'baz']));
        $model = m::mock(PluckStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('hasAnyGetMutator')->with('name')->andReturn(true);
        $model->expects('newFromBuilder')->with(['name' => 'bar'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'bar']));
        $model->expects('newFromBuilder')->with(['name' => 'baz'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'baz']));
        $builder->setModel($model);

        $this->assertEquals(['foo_bar', 'foo_baz'], $builder->pluck('name')->all());
    }

    public function testPluckReturnsTheCastedAttributesOfAModel(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with('name', '')->andReturn(new BaseCollection(['bar', 'baz']));
        $model = m::mock(PluckStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('hasAnyGetMutator')->with('name')->andReturn(false);
        $model->expects('hasCast')->with('name')->andReturn(true);
        $model->expects('newFromBuilder')->with(['name' => 'bar'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'bar']));
        $model->expects('newFromBuilder')->with(['name' => 'baz'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'baz']));
        $builder->setModel($model);

        $this->assertEquals(['foo_bar', 'foo_baz'], $builder->pluck('name')->all());
    }

    public function testPluckReturnsTheDateAttributesOfAModel(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with('created_at', '')->andReturn(new BaseCollection(['2010-01-01 00:00:00', '2011-01-01 00:00:00']));
        $model = m::mock(PluckDatesStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('hasAnyGetMutator')->with('created_at')->andReturn(false);
        $model->expects('hasCast')->with('created_at')->andReturn(false);
        $model->expects('getDates')->andReturn(['created_at']);
        $model->expects('newFromBuilder')->with(['created_at' => '2010-01-01 00:00:00'])->andReturn(m::mock(PluckDatesStub::class)->makePartial()->setRawAttributes(['created_at' => '2010-01-01 00:00:00']));
        $model->expects('newFromBuilder')->with(['created_at' => '2011-01-01 00:00:00'])->andReturn(m::mock(PluckDatesStub::class)->makePartial()->setRawAttributes(['created_at' => '2011-01-01 00:00:00']));
        $builder->setModel($model);

        $this->assertEquals(['date_2010-01-01 00:00:00', 'date_2011-01-01 00:00:00'], $builder->pluck('created_at')->all());
    }

    public function testQualifiedPluckReturnsTheMutatedAttributesOfAModel(): void
    {
        $model = m::mock(PluckStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('qualifyColumn')->times(2)->with('name')->andReturn('foo_table.name');
        $model->expects('hasAnyGetMutator')->with('name')->andReturn(true);
        $model->expects('newFromBuilder')->with(['name' => 'bar'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'bar']));
        $model->expects('newFromBuilder')->with(['name' => 'baz'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'baz']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with($model->qualifyColumn('name'), '')->andReturn(new BaseCollection(['bar', 'baz']));
        $builder->setModel($model);

        $this->assertEquals(['foo_bar', 'foo_baz'], $builder->pluck($model->qualifyColumn('name'))->all());
    }

    public function testQualifiedPluckReturnsTheCastedAttributesOfAModel(): void
    {
        $model = m::mock(PluckStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('qualifyColumn')->times(2)->with('name')->andReturn('foo_table.name');
        $model->expects('hasAnyGetMutator')->with('name')->andReturn(false);
        $model->expects('hasCast')->with('name')->andReturn(true);
        $model->expects('newFromBuilder')->with(['name' => 'bar'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'bar']));
        $model->expects('newFromBuilder')->with(['name' => 'baz'])->andReturn(m::mock(PluckStub::class)->makePartial()->setRawAttributes(['name' => 'baz']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with($model->qualifyColumn('name'), '')->andReturn(new BaseCollection(['bar', 'baz']));
        $builder->setModel($model);

        $this->assertEquals(['foo_bar', 'foo_baz'], $builder->pluck($model->qualifyColumn('name'))->all());
    }

    public function testQualifiedPluckReturnsTheDateAttributesOfAModel(): void
    {
        $model = m::mock(PluckDatesStub::class)->makePartial();
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
        $model->expects('qualifyColumn')->times(2)->with('created_at')->andReturn('foo_table.created_at');
        $model->expects('hasAnyGetMutator')->with('created_at')->andReturn(false);
        $model->expects('hasCast')->with('created_at')->andReturn(false);
        $model->expects('getDates')->andReturn(['created_at']);
        $model->expects('newFromBuilder')->with(['created_at' => '2010-01-01 00:00:00'])->andReturn(m::mock(PluckDatesStub::class)->makePartial()->setRawAttributes(['created_at' => '2010-01-01 00:00:00']));
        $model->expects('newFromBuilder')->with(['created_at' => '2011-01-01 00:00:00'])->andReturn(m::mock(PluckDatesStub::class)->makePartial()->setRawAttributes(['created_at' => '2011-01-01 00:00:00']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with($model->qualifyColumn('created_at'), '')->andReturn(new BaseCollection(['2010-01-01 00:00:00', '2011-01-01 00:00:00']));
        $builder->setModel($model);

        $this->assertEquals(['date_2010-01-01 00:00:00', 'date_2011-01-01 00:00:00'], $builder->pluck($model->qualifyColumn('created_at'))->all());
    }

    public function testPluckWithoutModelGetterJustReturnsTheAttributesFoundInDatabase(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('pluck')->with('name', '')->andReturn(new BaseCollection(['bar', 'baz']));
        $builder->setModel($this->getMockModel());
        $builder->getModel()->expects('hasAnyGetMutator')->with('name')->andReturn(false);
        $builder->getModel()->expects('hasCast')->with('name')->andReturn(false);
        $builder->getModel()->expects('getDates')->andReturn(['created_at']);

        $this->assertEquals(['bar', 'baz'], $builder->pluck('name')->all());
    }

    public function testLocalMacrosAreCalledOnBuilder()
    {
        unset($_SERVER['__test.builder']);
        $builder = new Builder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));
        $builder->macro('fooBar', function ($builder) {
            $_SERVER['__test.builder'] = $builder;

            return $builder;
        });
        $result = $builder->fooBar();

        $this->assertTrue($builder->hasMacro('fooBar'));
        $this->assertEquals($builder, $result);
        $this->assertEquals($builder, $_SERVER['__test.builder']);
        unset($_SERVER['__test.builder']);
    }

    public function testGlobalMacrosAreCalledOnBuilder()
    {
        Builder::macro('foo', function ($bar) {
            return $bar;
        });

        Builder::macro('bam', function () {
            return $this->getQuery();
        });

        $builder = $this->getBuilder();

        $this->assertTrue(Builder::hasGlobalMacro('foo'));
        $this->assertSame('bar', $builder->foo('bar'));
        $this->assertEquals($builder->bam(), $builder->getQuery());
    }

    public function testMissingStaticMacrosThrowsProperException(): void
    {
        $this->expectExceptionObject(new BadMethodCallException('Call to undefined method Hypervel\Database\Eloquent\Builder::missingMacro()'));

        Builder::missingMacro();
    }

    public function testGetModelsProperlyHydratesModels()
    {
        $builder = m::mock(Builder::class . '[get]', [$this->getMockQueryBuilder()]);
        $records[] = ['name' => 'taylor', 'age' => 26];
        $records[] = ['name' => 'dayle', 'age' => 28];
        $builder->getQuery()->expects('get')->with(['foo'])->andReturn(new BaseCollection($records));
        $model = m::mock(Model::class . '[getTable,hydrate]');
        $model->expects('getTable')->andReturn('foo_table');
        $builder->setModel($model);
        $model->expects('hydrate')->with($records)->andReturn(new Collection(['hydrated']));
        $models = $builder->getModels(['foo']);

        $this->assertEquals(['hydrated'], $models);
    }

    public function testEagerLoadRelationsLoadTopLevelRelationships(): void
    {
        $builder = m::mock(Builder::class . '[eagerLoadRelation]', [$this->getMockQueryBuilder()]);
        $nop1 = function (): void {
        };
        $nop2 = function (): void {
        };
        $builder->setEagerLoads(['foo' => $nop1, 'foo.bar' => $nop2]);
        $builder->shouldAllowMockingProtectedMethods()->expects('eagerLoadRelation')->with(['models'], 'foo', $nop1)->andReturn(['foo']);

        $results = $builder->eagerLoadRelations(['models']);
        $this->assertEquals(['foo'], $results);
    }

    public function testEagerLoadRelationsCanBeFlushed()
    {
        $builder = m::mock(Builder::class . '[eagerLoadRelation]', [$this->getMockQueryBuilder()]);

        $builder->setEagerLoads(['foo']);

        $this->assertSame(['foo'], $builder->getEagerLoads());

        $builder->withoutEagerLoads();

        $this->assertEmpty($builder->getEagerLoads());
    }

    public function testRelationshipEagerLoadProcess()
    {
        $builder = m::mock(Builder::class . '[getRelation]', [$this->getMockQueryBuilder()]);
        $builder->setEagerLoads(['orders' => function ($query) {
            $_SERVER['__eloquent.constrain'] = $query;
        }]);
        $relation = m::mock(Relation::class);
        $relation->expects('addEagerConstraints')->with(['models']);
        $relation->expects('initRelation')->with(['models'], 'orders')->andReturn(['models']);
        $eagerResults = new Collection(['results']);
        $relation->expects('getEager')->andReturn($eagerResults);
        $relation->expects('match')->with(['models'], $eagerResults, 'orders')->andReturn(['models.matched']);
        $builder->expects('getRelation')->with('orders')->andReturn($relation);
        $results = $builder->eagerLoadRelations(['models']);

        $this->assertEquals(['models.matched'], $results);
        $this->assertEquals($relation, $_SERVER['__eloquent.constrain']);
        unset($_SERVER['__eloquent.constrain']);
    }

    public function testRelationshipEagerLoadProcessForImplicitlyEmpty()
    {
        $queryBuilder = $this->getMockQueryBuilder();
        $builder = m::mock(Builder::class . '[getRelation]', [$queryBuilder]);
        $builder->setEagerLoads(['parentFoo' => function ($query) {
            $_SERVER['__eloquent.constrain'] = $query;
        }]);
        $model = new ModelSelfRelatedStub;
        $this->mockConnectionForModel($model, 'SQLite');

        $models = [
            new ModelSelfRelatedStub,
            new ModelSelfRelatedStub,
        ];
        $relation = m::mock($model->parentFoo());

        $builder->expects('getRelation')->with('parentFoo')->andReturn($relation);

        $results = $builder->eagerLoadRelations($models);

        unset($_SERVER['__eloquent.constrain']);
    }

    public function testGetRelationProperlySetsNestedRelationships(): void
    {
        $builder = $this->getBuilder();
        $builder->setModel($this->getMockModel());
        $relation = m::mock(Relation::class);
        $builder->getModel()->expects('newInstance->orders')->andReturn($relation);
        $relationQuery = m::mock(Builder::class);
        $relation->expects('getQuery')->andReturn($relationQuery);
        $relationQuery->expects('with')->with(['lines' => null, 'lines.details' => null]);
        $builder->setEagerLoads(['orders' => null, 'orders.lines' => null, 'orders.lines.details' => null]);

        $builder->getRelation('orders');
    }

    public function testGetRelationProperlySetsNestedRelationshipsWithSimilarNames(): void
    {
        $builder = $this->getBuilder();
        $builder->setModel($this->getMockModel());
        $relation = m::mock(Relation::class);
        $groupsRelation = m::mock(Relation::class);
        $builder->getModel()->expects('newInstance->orders')->andReturn($relation);
        $builder->getModel()->expects('newInstance->ordersGroups')->andReturn($groupsRelation);

        $relationQuery = m::mock(Builder::class);
        $relation->shouldReceive('getQuery')->andReturn($relationQuery);

        $groupRelationQuery = m::mock(Builder::class);
        $groupsRelation->expects('getQuery')->andReturn($groupRelationQuery);
        $groupRelationQuery->expects('with')->with(['lines' => null, 'lines.details' => null]);

        $builder->setEagerLoads(['orders' => null, 'ordersGroups' => null, 'ordersGroups.lines' => null, 'ordersGroups.lines.details' => null]);

        $builder->getRelation('orders');
        $builder->getRelation('ordersGroups');
    }

    public function testGetRelationThrowsException()
    {
        $this->expectException(RelationNotFoundException::class);

        $builder = $this->getBuilder();
        $builder->setModel($this->getMockModel());

        $builder->getRelation('invalid');
    }

    public function testEagerLoadParsingSetsProperRelationships()
    {
        $builder = $this->getBuilder();
        $builder->with(['orders', 'orders.lines']);
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf(Closure::class, $eagers['orders']);
        $this->assertInstanceOf(Closure::class, $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with('orders', 'orders.lines');
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf(Closure::class, $eagers['orders']);
        $this->assertInstanceOf(Closure::class, $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with(['orders.lines']);
        $eagers = $builder->getEagerLoads();

        $this->assertEquals(['orders', 'orders.lines'], array_keys($eagers));
        $this->assertInstanceOf(Closure::class, $eagers['orders']);
        $this->assertInstanceOf(Closure::class, $eagers['orders.lines']);

        $builder = $this->getBuilder();
        $builder->with(['orders' => function () {
            return 'foo';
        }]);
        $eagers = $builder->getEagerLoads();

        $this->assertSame('foo', $eagers['orders']($this->getBuilder()));

        $builder = $this->getBuilder();
        $builder->with(['orders.lines' => function () {
            return 'foo';
        }]);
        $eagers = $builder->getEagerLoads();

        $this->assertInstanceOf(Closure::class, $eagers['orders']);
        $this->assertNull($eagers['orders']());
        $this->assertSame('foo', $eagers['orders.lines']($this->getBuilder()));

        $builder = $this->getBuilder();
        $builder->with('orders.lines', function () {
            return 'foo';
        });
        $eagers = $builder->getEagerLoads();

        $this->assertInstanceOf(Closure::class, $eagers['orders']);
        $this->assertNull($eagers['orders']());
        $this->assertSame('foo', $eagers['orders.lines']($this->getBuilder()));
    }

    public function testQueryPassThru(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('foobar')->andReturn('foo');

        $this->assertInstanceOf(Builder::class, $builder->foobar());

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('insert')->with(['bar'])->andReturn(true);

        $this->assertTrue($builder->insert(['bar']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('insertOrIgnore')->with(['bar'])->andReturn(1);

        $this->assertSame(1, $builder->insertOrIgnore(['bar']));

        $builder = $this->getBuilder();
        $inserted = new BaseCollection([(object) ['baz' => 'foo']]);
        $builder->getQuery()->expects('insertOrIgnoreReturning')->with(['bar'], ['baz'])->andReturn($inserted);

        $this->assertSame($inserted, $builder->insertOrIgnoreReturning(['bar'], ['baz']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('insertOrIgnoreUsing')->with(['bar'], 'baz')->andReturn(1);

        $this->assertSame(1, $builder->insertOrIgnoreUsing(['bar'], 'baz'));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('insertGetId')->with(['bar'])->andReturn(123);

        $this->assertSame(123, $builder->insertGetId(['bar']));

        $builder = $this->getBuilder();
        $builder->getQuery()->expects('insertUsing')->with(['bar'], 'baz')->andReturn(1);

        $this->assertSame(1, $builder->insertUsing(['bar'], 'baz'));

        $builder = $this->getBuilder();
        $expression = new Expression('foo');
        $builder->getQuery()->expects('raw')->with('bar')->andReturn($expression);

        $this->assertSame($expression, $builder->raw('bar'));
    }

    public function testQueryScopes()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->getQuery()->expects('where')->with('foo', 'bar');
        $builder->setModel($model = new ScopeStub);
        $result = $builder->approved();

        $this->assertEquals($builder, $result);
    }

    public function testQueryDynamicScopes()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->getQuery()->expects('where')->with('bar', 'foo');
        $builder->setModel($model = new DynamicScopeStub);
        $result = $builder->dynamic('bar', 'foo');

        $this->assertEquals($builder, $result);
    }

    public function testQueryDynamicScopesNamed()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->getQuery()->expects('where')->with('foo', 'foo');
        $builder->setModel($model = new DynamicScopeStub);
        $result = $builder->dynamic(bar: 'foo');

        $this->assertEquals($builder, $result);
    }

    public function testApplyScopeCallbackReceivesAndReturnsSameBuilder(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery();
        $differentQuery = $model->newQuery();
        $receivedQuery = null;

        $result = $query->applyScopeCallback(function (Builder $scopeQuery) use (&$receivedQuery, $differentQuery): Builder {
            $receivedQuery = $scopeQuery;

            return $differentQuery;
        });

        $this->assertSame($query, $receivedQuery);
        $this->assertNotSame($query, $differentQuery);
        $this->assertSame($query, $result);
    }

    public function testApplyScopeCallbackGroupsExistingAndCallbackOrConditions(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()
            ->where('tenant_id', 1)
            ->orWhere('is_active', true);

        $query->applyScopeCallback(function (Builder $scopeQuery): void {
            $scopeQuery->where('status', 'draft')->orWhere('is_public', true);
        });

        $this->assertSame(
            'select * from "table" where ("tenant_id" = ? or "is_active" = ?) and ("status" = ? or "is_public" = ?)',
            $query->toSql(),
        );
        $this->assertSame([1, true, 'draft', true], $query->getBindings());
    }

    public function testApplyScopeCallbackGroupsStructuredPredicateSlices(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->where('status', 'active');

        $query->applyScopeCallback(function (Builder $scopeQuery): void {
            $scopeQuery->where('tenant_id', 1);
        });

        $this->assertSame(
            'select * from "table" where ("status" = ?) and ("tenant_id" = ?)',
            $query->toSql(),
        );
        $this->assertSame(['active', 1], $query->getBindings());
    }

    public function testApplyScopesPreserveSingleNegatedUserPredicate(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()
            ->whereNot('status', 'inactive')
            ->withGlobalScope('tenant', function (Builder $scopeQuery): void {
                $scopeQuery->where('tenant_id', 1);
            });

        $this->assertSame(
            'select * from "table" where (not "status" = ?) and ("tenant_id" = ?)',
            $query->toSql(),
        );
        $this->assertSame(['inactive', 1], $query->getBindings());
    }

    public function testApplyScopesGroupOpaqueUserPredicates(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $scope = function (Builder $scopeQuery): void {
            $scopeQuery->where('tenant_id', 1);
        };

        $rawQuery = $model->newQuery()
            ->whereRaw('1 = 1 OR tenant_id = ?', [2])
            ->withGlobalScope('tenant', $scope);

        $this->assertSame(
            'select * from "table" where (1 = 1 OR tenant_id = ?) and ("tenant_id" = ?)',
            $rawQuery->toSql(),
        );
        $this->assertSame([2, 1], $rawQuery->getBindings());

        $expressionQuery = $model->newQuery()
            ->whereNull(new Expression('1 = 1 OR name'))
            ->withGlobalScope('tenant', $scope);

        $this->assertSame(
            'select * from "table" where (1 = 1 OR name is null) and ("tenant_id" = ?)',
            $expressionQuery->toSql(),
        );
        $this->assertSame([1], $expressionQuery->getBindings());
    }

    public function testApplyScopesGroupOpaqueScopePredicates(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()
            ->where('status', 'active')
            ->withGlobalScope('visibility', function (Builder $scopeQuery): void {
                $scopeQuery->whereRaw('tenant_id = 1 OR is_public = 1');
            });

        $this->assertSame(
            'select * from "table" where ("status" = ?) and (tenant_id = 1 OR is_public = 1)',
            $query->toSql(),
        );
        $this->assertSame(['active'], $query->getBindings());
    }

    public function testApplyScopeCallbackWithoutExistingWheresKeepsScopeConditionsGroupedForLaterConstraints(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery();

        $query->applyScopeCallback(function (Builder $scopeQuery): void {
            $scopeQuery->where('tenant_id', 1)
                ->orWhere('is_public', true);
        });

        $query->where('status', 'active');

        $this->assertSame(
            'select * from "table" where ("tenant_id" = ? or "is_public" = ?) and "status" = ?',
            $query->toSql(),
        );
        $this->assertSame([1, true, 'active'], $query->getBindings());
    }

    public function testApplyScopeCallbackWithoutWheresLeavesQueryUnchanged(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery();
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $query->applyScopeCallback(static function (): void {
        });

        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
    }

    public function testNestedWhere()
    {
        $nestedQuery = m::mock(Builder::class);
        $nestedRawQuery = $this->getMockQueryBuilder();
        $nestedQuery->expects('getQuery')->andReturn($nestedRawQuery);
        $nestedQuery->expects('getEagerLoads')->andReturn([]);
        $nestedQuery->expects('removedScopes')->andReturn([]);
        $model = $this->getMockModel()->makePartial();
        $model->expects('newQueryWithoutRelationships')->andReturn($nestedQuery);
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->setModel($model);
        $builder->getQuery()->expects('addNestedWhereQuery')->with($nestedRawQuery, 'and');
        $nestedQuery->expects('foo');

        $result = $builder->where(function ($query) {
            $query->foo();
        });
        $this->assertEquals($builder, $result);
    }

    public function testRealNestedWhereWithScopes()
    {
        $model = new NestedStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->where('foo', '=', 'bar')->where(function ($query) {
            $query->where('baz', '>', 9000);
        });
        $this->assertSame('select * from "table" where ("foo" = ? and ("baz" > ?)) and ("table"."deleted_at" is null)', $query->toSql());
        $this->assertEquals(['bar', 9000], $query->getBindings());
    }

    public function testRealNestedWhereWithScopesMacro()
    {
        $model = new NestedStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->where('foo', '=', 'bar')->where(function ($query) {
            $query->where('baz', '>', 9000)->onlyTrashed();
        })->withTrashed();
        $this->assertSame('select * from "table" where "foo" = ? and ("baz" > ? and "table"."deleted_at" is not null)', $query->toSql());
        $this->assertEquals(['bar', 9000], $query->getBindings());
    }

    public function testRealNestedWhereWithMultipleScopesAndOneDeadScope()
    {
        $model = new NestedStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->empty()->where('foo', '=', 'bar')->empty()->where(function ($query) {
            $query->empty()->where('baz', '>', 9000);
        });
        $this->assertSame('select * from "table" where ("foo" = ? and ("baz" > ?)) and ("table"."deleted_at" is null)', $query->toSql());
        $this->assertEquals(['bar', 9000], $query->getBindings());
    }

    public function testSimpleWhereNot()
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->whereNot('name', 'foo')->whereNot('name', '<>', 'bar');
        $this->assertEquals('select * from "table" where not "name" = ? and not "name" <> ?', $query->toSql());
        $this->assertEquals(['foo', 'bar'], $query->getBindings());
    }

    public function testWhereNot()
    {
        $nestedQuery = m::mock(Builder::class);
        $nestedRawQuery = $this->getMockQueryBuilder();
        $nestedQuery->expects('getQuery')->andReturn($nestedRawQuery);
        $nestedQuery->expects('getEagerLoads')->andReturn([]);
        $nestedQuery->expects('removedScopes')->andReturn([]);
        $model = $this->getMockModel()->makePartial();
        $model->expects('newQueryWithoutRelationships')->andReturn($nestedQuery);
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->setModel($model);
        $builder->getQuery()->expects('addNestedWhereQuery')->with($nestedRawQuery, 'and not');
        $nestedQuery->expects('foo');

        $result = $builder->whereNot(function ($query) {
            $query->foo();
        });
        $this->assertEquals($builder, $result);
    }

    public function testSimpleOrWhereNot()
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->orWhereNot('name', 'foo')->orWhereNot('name', '<>', 'bar');
        $this->assertEquals('select * from "table" where not "name" = ? or not "name" <> ?', $query->toSql());
        $this->assertEquals(['foo', 'bar'], $query->getBindings());
    }

    public function testOrWhereNot()
    {
        $nestedQuery = m::mock(Builder::class);
        $nestedRawQuery = $this->getMockQueryBuilder();
        $nestedQuery->expects('getQuery')->andReturn($nestedRawQuery);
        $nestedQuery->expects('getEagerLoads')->andReturn([]);
        $nestedQuery->expects('removedScopes')->andReturn([]);
        $model = $this->getMockModel()->makePartial();
        $model->expects('newQueryWithoutRelationships')->andReturn($nestedQuery);
        $builder = $this->getBuilder();
        $builder->getQuery()->shouldReceive('from');
        $builder->setModel($model);
        $builder->getQuery()->expects('addNestedWhereQuery')->with($nestedRawQuery, 'or not');
        $nestedQuery->expects('foo');

        $result = $builder->orWhereNot(function ($query) {
            $query->foo();
        });
        $this->assertEquals($builder, $result);
    }

    public function testQueryableWhereForwardersAcceptBuilderAndRelationSubqueries(): void
    {
        $model = new ModelParentStub;
        $model->foo_id = 7;
        $connection = $this->mockConnectionForModel($model, 'SQLite');
        $subquery = $connection->query()
            ->select('score')
            ->from('scores')
            ->where('active', true);

        $builder = $model->newQuery()
            ->where($model->foo(), '>', 5)
            ->orWhere($subquery, '<', 4)
            ->whereNot($subquery, '=', 3)
            ->orWhereNot($subquery, '=', 2);

        $this->assertSame(
            'select * from "model_parent_stubs" where (select * from "model_close_related_stubs" where "model_close_related_stubs"."id" = ?) > ? or (select "score" from "scores" where "active" = ?) < ? and not (select "score" from "scores" where "active" = ?) = ? or not (select "score" from "scores" where "active" = ?) = ?',
            $builder->toSql()
        );
        $this->assertSame([7, 5, true, 4, true, 3, true, 2], $builder->getBindings());
    }

    public function testFirstWhereAcceptsRelationSubquery(): void
    {
        $model = new ModelParentStub;
        $model->foo_id = 7;
        $connection = $this->mockConnectionForModel($model, 'SQLite');
        $connection->shouldReceive('getWritableName')->andReturn('database');
        $connection->expects('select')->with(
            'select * from "model_parent_stubs" where (select * from "model_close_related_stubs" where "model_close_related_stubs"."id" = ?) > ? limit 1',
            [7, 5],
            true,
            [],
        )->andReturn([['id' => 11]]);

        $result = $model->newQuery()->firstWhere($model->foo(), '>', 5);

        $this->assertInstanceOf(ModelParentStub::class, $result);
        $this->assertSame(11, $result->id);
    }

    public function testRealQueryHigherOrderOrWhereScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->orWhere->two();
        $this->assertSame('select * from "table" where ("one" = ?) or (("two" = ?))', $query->toSql());
    }

    public function testRealQueryChainedHigherOrderOrWhereScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->orWhere->two()->orWhere->three();
        $this->assertSame('select * from "table" where ("one" = ?) or (("two" = ?)) or (("three" = ?))', $query->toSql());
    }

    public function testRealQueryHigherOrderWhereNotScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->whereNot->two();
        $this->assertSame('select * from "table" where ("one" = ?) and not (("two" = ?))', $query->toSql());
    }

    public function testRealQueryChainedHigherOrderWhereNotScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->whereNot->two()->whereNot->three();
        $this->assertSame('select * from "table" where ("one" = ?) and not (("two" = ?)) and not (("three" = ?))', $query->toSql());
    }

    public function testRealQueryHigherOrderOrWhereNotScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->orWhereNot->two();
        $this->assertSame('select * from "table" where ("one" = ?) or not (("two" = ?))', $query->toSql());
    }

    public function testRealQueryChainedHigherOrderOrWhereNotScopes()
    {
        $model = new HigherOrderWhereScopeStub;
        $this->mockConnectionForModel($model, 'SQLite');
        $query = $model->newQuery()->one()->orWhereNot->two()->orWhereNot->three();
        $this->assertSame('select * from "table" where ("one" = ?) or not (("two" = ?)) or not (("three" = ?))', $query->toSql());
    }

    public function testSimpleWhere()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('where')->with('foo', '=', 'bar');
        $result = $builder->where('foo', '=', 'bar');
        $this->assertEquals($result, $builder);
    }

    public function testPostgresOperatorsWhere()
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('where')->with('foo', '@>', 'bar');
        $result = $builder->where('foo', '@>', 'bar');
        $this->assertEquals($result, $builder);
    }

    public function testWhereBelongsTo()
    {
        $related = new WhereBelongsToStub([
            'id' => 1,
            'parent_id' => 2,
        ]);

        $parent = new WhereBelongsToStub([
            'id' => 2,
            'parent_id' => 1,
        ]);

        $builder = $this->getBuilder();
        $builder->shouldReceive('from')->with('where_belongs_to_stubs');
        $builder->setModel($related);
        $builder->getQuery()->expects('whereIn')->with('where_belongs_to_stubs.parent_id', [2], 'and');

        $result = $builder->whereBelongsTo($parent);
        $this->assertEquals($result, $builder);

        $builder = $this->getBuilder();
        $builder->shouldReceive('from')->with('where_belongs_to_stubs');
        $builder->setModel($related);
        $builder->getQuery()->expects('whereIn')->with('where_belongs_to_stubs.parent_id', [2], 'and');

        $result = $builder->whereBelongsTo($parent, 'parent');
        $this->assertEquals($result, $builder);

        $parents = new Collection([new WhereBelongsToStub([
            'id' => 2,
            'parent_id' => 1,
        ]), new WhereBelongsToStub([
            'id' => 3,
            'parent_id' => 1,
        ])]);

        $builder = $this->getBuilder();
        $builder->shouldReceive('from')->with('where_belongs_to_stubs');
        $builder->setModel($related);
        $builder->getQuery()->expects('whereIn')->with('where_belongs_to_stubs.parent_id', [2, 3], 'and');

        $result = $builder->whereBelongsTo($parents);
        $this->assertEquals($result, $builder);

        $builder = $this->getBuilder();
        $builder->shouldReceive('from')->with('where_belongs_to_stubs');
        $builder->setModel($related);
        $builder->getQuery()->expects('whereIn')->with('where_belongs_to_stubs.parent_id', [2, 3], 'and');

        $result = $builder->whereBelongsTo($parents, 'parent');
        $this->assertEquals($result, $builder);
    }

    public function testWhereAttachedTo()
    {
        $related = new ModelFarRelatedStub;
        $related->id = 49;
        $related->name = 'test';

        $builder = ModelParentStub::whereAttachedTo($related, 'roles');

        $this->assertSame('select * from "model_parent_stubs" where exists (select * from "model_far_related_stubs" inner join "user_role" on "model_far_related_stubs"."id" = "user_role"."related_id" where ("model_parent_stubs"."id" = "user_role"."self_id") and ("model_far_related_stubs"."id" in (49)))', $builder->toSql());
    }

    public function testWhereAttachedToCollection()
    {
        $model1 = new ModelParentStub;
        $model1->id = 3;
        $model1->name = 'test3';

        $model2 = new ModelParentStub;
        $model2->id = 4;
        $model2->name = 'test4';

        $builder = ModelFarRelatedStub::whereAttachedTo(new Collection([$model1, $model2]), 'roles');

        $this->assertSame('select * from "model_far_related_stubs" where exists (select * from "model_parent_stubs" inner join "user_role" on "model_parent_stubs"."id" = "user_role"."self_id" where ("model_far_related_stubs"."id" = "user_role"."related_id") and ("model_parent_stubs"."id" in (3, 4)))', $builder->toSql());
    }

    public function testDeleteOverride()
    {
        $builder = $this->getBuilder();
        $builder->onDelete(function ($builder) {
            return ['foo' => $builder];
        });
        $this->assertEquals(['foo' => $builder], $builder->delete());
    }

    public function testWithCount()
    {
        $model = new ModelParentStub;

        $builder = $model->withCount('foo');

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithCountAndSelect()
    {
        $model = new ModelParentStub;

        $builder = $model->select('id')->withCount('foo');

        $this->assertSame('select "id", (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithCountSecondRelationWithClosure()
    {
        $model = new ModelParentStub;

        $builder = $model->withCount(['address', 'foo' => function ($query) {
            $query->where('active', false);
        }]);

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "address_count", (select count(*) from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("active" = ?)) as "foo_count" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithCountAndMergedWheres()
    {
        $model = new ModelParentStub;

        $builder = $model->select('id')->withCount(['activeFoo' => function ($q) {
            $q->where('bam', '>', 'qux');
        }]);

        $this->assertSame('select "id", (select count(*) from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("bam" > ?) and "active" = ?) as "active_foo_count" from "model_parent_stubs"', $builder->toSql());
        $this->assertEquals(['qux', true], $builder->getBindings());
    }

    public function testWithCountAndGlobalScope()
    {
        $model = new ModelParentStub;
        ModelCloseRelatedStub::addGlobalScope('withCount', function ($query) {
            return $query->addSelect('id');
        });

        $builder = $model->select('id')->withCount(['foo']);

        // Remove the global scope so it doesn't interfere with any other tests
        ModelCloseRelatedStub::addGlobalScope('withCount', function ($query) {
        });

        $this->assertSame('select "id", (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithMin()
    {
        $model = new ModelParentStub;

        $builder = $model->withMin('foo', 'price');

        $this->assertSame('select "model_parent_stubs".*, (select min("model_close_related_stubs"."price") from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_min_price" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithMinExpression()
    {
        $model = new ModelParentStub;

        $builder = $model->withMin('foo', new Expression('price - discount'));

        $this->assertSame('select "model_parent_stubs".*, (select min(price - discount) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_min_price_discount" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithMinOnBelongsToMany()
    {
        $model = new ModelParentStub;

        $builder = $model->withMin('roles', 'id');

        $this->assertSame('select "model_parent_stubs".*, (select min("model_far_related_stubs"."id") from "model_far_related_stubs" inner join "user_role" on "model_far_related_stubs"."id" = "user_role"."related_id" where "model_parent_stubs"."id" = "user_role"."self_id") as "roles_min_id" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithMinOnSelfRelated()
    {
        $model = new ModelSelfRelatedStub;

        $sql = $model->withMin('childFoos', 'created_at')->toSql();

        // alias has a dynamic hash, so replace with a static string for comparison
        $alias = 'self_alias_hash';
        $aliasRegex = '/\b(hypervel_reserved_\d)(\b|$)/i';

        $sql = preg_replace($aliasRegex, $alias, $sql);

        $this->assertSame('select "self_related_stubs".*, (select min("self_alias_hash"."created_at") from "self_related_stubs" as "self_alias_hash" where "self_related_stubs"."id" = "self_alias_hash"."parent_id") as "child_foos_min_created_at" from "self_related_stubs"', $sql);
    }

    public function testWithMax()
    {
        $model = new ModelParentStub;

        $builder = $model->withMax('foo', 'price');

        $this->assertSame('select "model_parent_stubs".*, (select max("model_close_related_stubs"."price") from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_max_price" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithMaxExpression()
    {
        $model = new ModelParentStub;

        $builder = $model->withMax('foo', new Expression('price - discount'));

        $this->assertSame('select "model_parent_stubs".*, (select max(price - discount) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_max_price_discount" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithAvg()
    {
        $model = new ModelParentStub;

        $builder = $model->withAvg('foo', 'price');

        $this->assertSame('select "model_parent_stubs".*, (select avg("model_close_related_stubs"."price") from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_avg_price" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWitAvgExpression()
    {
        $model = new ModelParentStub;

        $builder = $model->withAvg('foo', new Expression('price - discount'));

        $this->assertSame('select "model_parent_stubs".*, (select avg(price - discount) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_avg_price_discount" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithCountAndConstraintsAndHaving()
    {
        $model = new ModelParentStub;

        $builder = $model->where('bar', 'baz');
        $builder->withCount(['foo' => function ($q) {
            $q->where('bam', '>', 'qux');
        }])->having('foo_count', '>=', 1);

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("bam" > ?)) as "foo_count" from "model_parent_stubs" where "bar" = ? having "foo_count" >= ?', $builder->toSql());
        $this->assertEquals(['qux', 'baz', 1], $builder->getBindings());
    }

    public function testWithCountAndRename()
    {
        $model = new ModelParentStub;

        $builder = $model->withCount('foo as foo_bar');

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_bar" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithCountWithConstrainedDottedAlias(): void
    {
        $model = new ModelParentStub;

        $builder = $model->withCount(['foo as a.b' => function ($query): void {
            $query->where('active', true);
        }]);

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("active" = ?)) as "a.b" from "model_parent_stubs"', $builder->toSql());
        $this->assertSame([true], $builder->getBindings());
    }

    public function testWithCountMultipleAndPartialRename()
    {
        $model = new ModelParentStub;

        $builder = $model->withCount(['foo as foo_bar', 'foo']);

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_bar", (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithAggregateAlias()
    {
        $model = new ModelParentStub;

        $builder = $model->withAggregate('foo', new Expression('TIMESTAMPDIFF(SECOND, `created_at`, `updated_at`)'), 'sum');

        $this->assertSame(
            'select "model_parent_stubs".*, (select sum(TIMESTAMPDIFF(SECOND, `created_at`, `updated_at`)) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_sum_timestampdiffsecond_created_at_updated_at" from "model_parent_stubs"',
            $builder->toSql()
        );
    }

    public function testWithAggregateNumericExpression(): void
    {
        $model = new ModelParentStub;

        $this->assertSame(
            'select "model_parent_stubs".*, (select count(1) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count1" from "model_parent_stubs"',
            $model->withAggregate('foo', new Expression(1), 'count')->toSql()
        );
        $this->assertSame(
            'select "model_parent_stubs".*, (select sum(1.5) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_sum15" from "model_parent_stubs"',
            $model->withSum('foo', new Expression(1.5))->toSql()
        );
    }

    public function testWithAggregateAndSelfRelationConstrain()
    {
        Stub::resolveRelationUsing('children', function ($model) {
            return $model->hasMany(Stub::class, 'parent_id', 'id')->where('enum_value', new stdClass);
        });

        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $relationHash = $model->children()->getRelationCountHash(false);

        $builder = $model->withCount('children');

        $this->assertSame(vsprintf('select "table".*, (select count(*) from "table" as "%s" where "table"."id" = "%s"."parent_id" and "enum_value" = ?) as "children_count" from "table"', [$relationHash, $relationHash]), $builder->toSql());
    }

    public function testWithExists()
    {
        $model = new ModelParentStub;

        $builder = $model->withExists('foo');

        $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_exists" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithExistsRejectsConstraintTimeoutBeforeEmbeddingTheConstraint(): void
    {
        $this->assertRelationshipConstraintTimeoutRejected(function (Builder $builder): void {
            $builder->withExists(['foo' => function ($query): void {
                $query->where('active', true)->timeout(2);
            }]);
        });
    }

    public function testWithCountRejectsConstraintTimeoutBeforeEmbeddingTheConstraint(): void
    {
        $this->assertRelationshipConstraintTimeoutRejected(function (Builder $builder): void {
            $builder->withCount(['foo' => function ($query): void {
                $query->where('active', true)->timeout(2);
            }]);
        });
    }

    public function testWithExistsAndSelect()
    {
        $model = new ModelParentStub;

        $builder = $model->select('id')->withExists('foo');

        $this->assertSame('select "id", exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_exists" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithExistsAndMergedWheres()
    {
        $model = new ModelParentStub;

        $builder = $model->select('id')->withExists(['activeFoo' => function ($q) {
            $q->where('bam', '>', 'qux');
        }]);

        $this->assertSame('select "id", exists(select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("bam" > ?) and "active" = ?) as "active_foo_exists" from "model_parent_stubs"', $builder->toSql());
        $this->assertEquals(['qux', true], $builder->getBindings());
    }

    public function testWithExistsAndGlobalScope()
    {
        $model = new ModelParentStub;
        ModelCloseRelatedStub::addGlobalScope('withExists', function ($query) {
            return $query->addSelect('id');
        });

        $builder = $model->select('id')->withExists(['foo']);

        // Remove the global scope so it doesn't interfere with any other tests
        ModelCloseRelatedStub::addGlobalScope('withExists', function ($query) {
        });

        $this->assertSame('select "id", exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_exists" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithExistsOnBelongsToMany()
    {
        $model = new ModelParentStub;

        $builder = $model->withExists('roles');

        $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_far_related_stubs" inner join "user_role" on "model_far_related_stubs"."id" = "user_role"."related_id" where "model_parent_stubs"."id" = "user_role"."self_id") as "roles_exists" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithExistsOnSelfRelated()
    {
        $model = new ModelSelfRelatedStub;

        $sql = $model->withExists('childFoos')->toSql();

        // alias has a dynamic hash, so replace with a static string for comparison
        $alias = 'self_alias_hash';
        $aliasRegex = '/\b(hypervel_reserved_\d)(\b|$)/i';

        $sql = preg_replace($aliasRegex, $alias, $sql);

        $this->assertSame('select "self_related_stubs".*, exists(select * from "self_related_stubs" as "self_alias_hash" where "self_related_stubs"."id" = "self_alias_hash"."parent_id") as "child_foos_exists" from "self_related_stubs"', $sql);
    }

    public function testWithExistsAndRename()
    {
        $model = new ModelParentStub;

        $builder = $model->withExists('foo as foo_bar');

        $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_bar" from "model_parent_stubs"', $builder->toSql());
    }

    public function testWithExistsWithLiteralAliases(): void
    {
        foreach (['a.b', 'data->x'] as $alias) {
            $model = new ModelParentStub;

            $builder = $model->withExists('foo as ' . $alias);

            $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "' . $alias . '" from "model_parent_stubs"', $builder->toSql());
            $this->assertSame([], $builder->getBindings());
        }
    }

    public function testWithExistsMultipleAndPartialRename()
    {
        $model = new ModelParentStub;

        $builder = $model->withExists(['foo as foo_bar', 'foo']);

        $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_bar", exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_exists" from "model_parent_stubs"', $builder->toSql());
    }

    public function testHasWithConstraintsAndHavingInSubquery()
    {
        $model = new ModelParentStub;

        $builder = $model->where('bar', 'baz');
        $builder->whereHas('foo', function ($q) {
            $q->having('bam', '>', 'qux');
        })->where('quux', 'quuux');

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? and exists (select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id" having "bam" > ?) and "quux" = ?', $builder->toSql());
        $this->assertEquals(['baz', 'qux', 'quuux'], $builder->getBindings());
    }

    public function testHasWithConstraintsWithOrWhereAndHavingInSubquery()
    {
        $model = new ModelParentStub;

        $builder = $model->where('name', 'larry');
        $builder->whereHas('address', function ($q) {
            $q->where('zipcode', '90210');
            $q->orWhere('zipcode', '90220');
            $q->having('street', '=', 'fooside dr');
        })->where('age', 29);

        $this->assertSame('select * from "model_parent_stubs" where "name" = ? and exists (select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("zipcode" = ? or "zipcode" = ?) having "street" = ?) and "age" = ?', $builder->toSql());
        $this->assertEquals(['larry', '90210', '90220', 'fooside dr', 29], $builder->getBindings());
    }

    public function testHasWithConstraintsWithOrWhereAndSubqueryInRelationFromClause()
    {
        ModelParentStub::resolveRelationUsing('addressAsExpression', function ($model) {
            return $model->address()->fromSub(ModelCloseRelatedStub::query(), 'model_close_related_stubs');
        });

        $model = new ModelParentStub;

        $builder = $model->where('name', 'larry');
        $builder->whereHas('addressAsExpression', function ($q) {
            $q->where('zipcode', '90210');
            $q->orWhere('zipcode', '90220');
            $q->having('street', '=', 'fooside dr');
        })->where('age', 29);

        $this->assertSame('select * from "model_parent_stubs" where "name" = ? and exists (select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("zipcode" = ? or "zipcode" = ?) having "street" = ?) and "age" = ?', $builder->toSql());
        $this->assertEquals(['larry', '90210', '90220', 'fooside dr', 29], $builder->getBindings());
    }

    public function testHasWithConstraintsAndJoinAndHavingInSubquery()
    {
        $model = new ModelParentStub;
        $builder = $model->where('bar', 'baz');
        $builder->whereHas('foo', function ($q) {
            $q->join('quuuux', function ($j) {
                $j->where('quuuuux', '=', 'quuuuuux');
            });
            $q->having('bam', '>', 'qux');
        })->where('quux', 'quuux');

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? and exists (select * from "model_close_related_stubs" inner join "quuuux" on "quuuuux" = ? where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id" having "bam" > ?) and "quux" = ?', $builder->toSql());
        $this->assertEquals(['baz', 'quuuuuux', 'qux', 'quuux'], $builder->getBindings());
    }

    public function testHasWithConstraintsAndHavingInSubqueryWithCount()
    {
        $model = new ModelParentStub;

        $builder = $model->where('bar', 'baz');
        $builder->whereHas('foo', function ($q) {
            $q->having('bam', '>', 'qux');
        }, '>=', 2)->where('quux', 'quuux');

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? and (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id" having "bam" > ?) >= 2 and "quux" = ?', $builder->toSql());
        $this->assertEquals(['baz', 'qux', 'quuux'], $builder->getBindings());
    }

    public function testRelationshipExistsRejectsConstraintTimeoutBeforeEmbeddingTheConstraint(): void
    {
        $this->assertRelationshipConstraintTimeoutRejected(function (Builder $builder): void {
            $builder->whereHas('foo', function ($query): void {
                $query->where('active', true)->timeout(2);
            });
        });
    }

    public function testRelationshipCountRejectsConstraintTimeoutBeforeEmbeddingTheConstraint(): void
    {
        $this->assertRelationshipConstraintTimeoutRejected(function (Builder $builder): void {
            $builder->whereHas('foo', function ($query): void {
                $query->where('active', true)->timeout(2);
            }, '>=', 2);
        });
    }

    public function testWithCountAndConstraintsWithBindingInSelectSub()
    {
        $model = new ModelParentStub;

        $builder = $model->newQuery();
        $builder->withCount(['foo' => function ($q) use ($model) {
            $q->selectSub($model->newQuery()->where('bam', '=', 3)->selectRaw('count(0)'), 'bam_3_count');
        }]);

        $this->assertSame('select "model_parent_stubs".*, (select count(*) from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_count" from "model_parent_stubs"', $builder->toSql());
        $this->assertSame([], $builder->getBindings());
    }

    public function testWithExistsAndConstraintsWithBindingInSelectSub()
    {
        $model = new ModelParentStub;

        $builder = $model->newQuery();
        $builder->withExists(['foo' => function ($q) use ($model) {
            $q->selectSub($model->newQuery()->where('bam', '=', 3)->selectRaw('count(0)'), 'bam_3_count');
        }]);

        $this->assertSame('select "model_parent_stubs".*, exists(select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") as "foo_exists" from "model_parent_stubs"', $builder->toSql());
        $this->assertSame([], $builder->getBindings());
    }

    public function testHasNestedWithConstraints()
    {
        $model = new ModelParentStub;

        $builder = $model->whereHas('foo', function ($q) {
            $q->whereHas('bar', function ($q) {
                $q->where('baz', 'bam');
            });
        })->toSql();

        $result = $model->whereHas('foo.bar', function ($q) {
            $q->where('baz', 'bam');
        })->toSql();

        $this->assertEquals($builder, $result);
    }

    public function testHasNested()
    {
        $model = new ModelParentStub;

        $builder = $model->whereHas('foo', function ($q) {
            $q->has('bar');
        });

        $result = $model->has('foo.bar')->toSql();

        $this->assertEquals($builder->toSql(), $result);
    }

    #[DataProvider('nestedRelationshipCountProvider')]
    public function testHasNestedWithMorphTo(ExpressionContract|int $count): void
    {
        $model = new ModelParentStub;
        $connection = $this->mockConnectionForModel($model, '');

        $morphToKey = $model->morph()->getMorphType();

        $connection->expects('select')->andReturn([
            [$morphToKey => ModelFarRelatedStub::class],
            [$morphToKey => ModelOtherFarRelatedStub::class],
        ]);

        $builder = $model->orWhereHasMorph('morph', [ModelFarRelatedStub::class], function ($q) use ($count) {
            $q->has('baz', '>=', $count);
        })->orWhereHasMorph('morph', [ModelOtherFarRelatedStub::class], function ($q) use ($count) {
            $q->has('baz', '>=', $count);
        });

        $results = $model->has('morph.baz', '>=', $count)->toSql();

        // Normalize the extra parentheses around the wildcard's grouped morph types.

        $builderSql = $builder->toSql();
        $builderSql = str_replace(')))) or ((', '))) or (', $builderSql);

        $this->assertSame($builderSql, $results);
    }

    #[DataProvider('nestedRelationshipCountProvider')]
    public function testHasNestedWithMorphToAndMultipleSubRelations(ExpressionContract|int $count): void
    {
        $model = new ModelParentStub;
        $connection = $this->mockConnectionForModel($model, '');

        $morphToKey = $model->morph()->getMorphType();

        $connection->expects('select')->andReturn([
            [$morphToKey => ModelFarRelatedStub::class],
            [$morphToKey => ModelOtherFarRelatedStub::class],
        ]);

        $builder = $model->orWhereHasMorph('morph', [ModelFarRelatedStub::class], function ($q) use ($count) {
            $q->has('baz.bam', '>=', $count);
        })->orWhereHasMorph('morph', [ModelOtherFarRelatedStub::class], function ($q) use ($count) {
            $q->has('baz.bam', '>=', $count);
        });

        $results = $model->has('morph.baz.bam', '>=', $count)->toSql();

        // Normalize the extra parentheses around the wildcard's grouped morph types.

        $builderSql = $builder->toSql();
        $builderSql = str_replace(')))) or ((', '))) or (', $builderSql);

        $this->assertSame($builderSql, $results);
    }

    /**
     * Provide counts that must survive each polymorphic branch.
     */
    public static function nestedRelationshipCountProvider(): array
    {
        return [
            'default count' => [1],
            'integer count' => [2],
            'expression count' => [new Expression('2')],
        ];
    }

    public function testHasNestedWithMorphToAfterFirstRelation(): void
    {
        ModelCloseRelatedStub::resolveRelationUsing('morph', static fn (ModelCloseRelatedStub $model) => $model->morphTo('morph'));

        $model = new ModelParentStub;
        $connection = $this->mockConnectionForModel($model, '');
        $connection->expects('select')->andReturn([
            ['morph_type' => ModelFarRelatedStub::class],
            ['morph_type' => ModelOtherFarRelatedStub::class],
        ]);

        $expected = $model->whereHas('foo', static function (Builder $query): void {
            $query->whereHasMorph('morph', [ModelFarRelatedStub::class, ModelOtherFarRelatedStub::class], static function (Builder $query): void {
                $query->has('baz');
            });
        });
        $actual = $model->whereHas('foo.morph.baz');

        $this->assertSame($expected->toSql(), $actual->toSql());
        $this->assertSame([ModelFarRelatedStub::class, ModelOtherFarRelatedStub::class], $actual->getBindings());
    }

    public function testHasWithCustomCountExpression(): void
    {
        $count = m::mock(ExpressionContract::class);
        $count->shouldReceive('getValue')->andReturn('model_parent_stubs.required_count');

        $query = (new ModelParentStub)->whereHas('foo', static function (Builder $query): void {
            $query->where('active', true);
        }, '>=', $count);

        $this->assertSame('select * from "model_parent_stubs" where (select count(*) from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("active" = ?)) >= model_parent_stubs.required_count', $query->toSql());
        $this->assertSame([true], $query->getBindings());
    }

    public function testOrHasNested()
    {
        $model = new ModelParentStub;

        $builder = $model->whereHas('foo', function ($q) {
            $q->has('bar');
        })->orWhereHas('foo', function ($q) {
            $q->has('baz');
        });

        $result = $model->has('foo.bar')->orHas('foo.baz')->toSql();

        $this->assertEquals($builder->toSql(), $result);
    }

    public function testSelfHasNested()
    {
        $model = new ModelSelfRelatedStub;

        $nestedSql = $model->whereHas('parentFoo', function ($q) {
            $q->has('childFoo');
        })->toSql();

        $dotSql = $model->has('parentFoo.childFoo')->toSql();

        // alias has a dynamic hash, so replace with a static string for comparison
        $alias = 'self_alias_hash';
        $aliasRegex = '/\b(hypervel_reserved_\d)(\b|$)/i';

        $nestedSql = preg_replace($aliasRegex, $alias, $nestedSql);
        $dotSql = preg_replace($aliasRegex, $alias, $dotSql);

        $this->assertEquals($nestedSql, $dotSql);
    }

    public function testSelfHasNestedUsesAlias()
    {
        $model = new ModelSelfRelatedStub;

        $sql = $model->has('parentFoo.childFoo')->toSql();

        // alias has a dynamic hash, so replace with a static string for comparison
        $alias = 'self_alias_hash';
        $aliasRegex = '/\b(hypervel_reserved_\d)(\b|$)/i';

        $sql = preg_replace($aliasRegex, $alias, $sql);

        $this->assertStringContainsString('"self_alias_hash"."id" = "self_related_stubs"."parent_id"', $sql);
    }

    public function testDoesntHave()
    {
        $model = new ModelParentStub;

        $builder = $model->doesntHave('foo');

        $this->assertSame('select * from "model_parent_stubs" where not exists (select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id")', $builder->toSql());
    }

    public function testDoesntHaveNested()
    {
        $model = new ModelParentStub;

        $builder = $model->doesntHave('foo.bar');

        $this->assertSame('select * from "model_parent_stubs" where not exists (select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and (exists (select * from "model_far_related_stubs" where "model_close_related_stubs"."id" = "model_far_related_stubs"."model_close_related_stub_id")))', $builder->toSql());
    }

    public function testOrDoesntHave()
    {
        $model = new ModelParentStub;

        $builder = $model->where('bar', 'baz')->orDoesntHave('foo');

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not exists (select * from "model_close_related_stubs" where "model_parent_stubs"."foo_id" = "model_close_related_stubs"."id")', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());
    }

    public function testWhereDoesntHave()
    {
        $model = new ModelParentStub;

        $builder = $model->whereDoesntHave('foo', function ($query) {
            $query->where('bar', 'baz');
        });

        $this->assertSame('select * from "model_parent_stubs" where not exists (select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("bar" = ?))', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());
    }

    public function testOrWhereDoesntHave()
    {
        $model = new ModelParentStub;

        $builder = $model->where('bar', 'baz')->orWhereDoesntHave('foo', function ($query) {
            $query->where('qux', 'quux');
        });

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not exists (select * from "model_close_related_stubs" where ("model_parent_stubs"."foo_id" = "model_close_related_stubs"."id") and ("qux" = ?))', $builder->toSql());
        $this->assertEquals(['baz', 'quux'], $builder->getBindings());
    }

    public function testWhereMorphedTo()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->whereMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from "model_parent_stubs" where (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals([$relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereMorphedToCollection()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelCloseRelatedStub;
        $secondRelatedModel->id = 2;

        $builder = $model->whereMorphedTo('morph', new Collection([$firstRelatedModel, $secondRelatedModel]));

        $this->assertSame('select * from "model_parent_stubs" where (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?, ?)))', $builder->toSql());
        $this->assertEquals([$firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $secondRelatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereMorphedToCollectionWithDifferentModels()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelFarRelatedStub;
        $secondRelatedModel->id = 2;

        $thirdRelatedModel = new ModelCloseRelatedStub;
        $thirdRelatedModel->id = 3;

        $builder = $model->whereMorphedTo('morph', [$firstRelatedModel, $secondRelatedModel, $thirdRelatedModel]);

        $this->assertSame('select * from "model_parent_stubs" where (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?, ?)) or ("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals([$firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $thirdRelatedModel->getKey(), $secondRelatedModel->getMorphClass(), $secondRelatedModel->id], $builder->getBindings());
    }

    public function testWhereMorphedToNull()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->whereMorphedTo('morph', null);
        $this->assertSame('select * from "model_parent_stubs" where "model_parent_stubs"."morph_type" is null', $builder->toSql());
    }

    public function testWhereNotMorphedToNull(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->whereNotMorphedTo('morph', null);

        $this->assertSame('select * from "model_parent_stubs" where "model_parent_stubs"."morph_type" is not null', $builder->toSql());
        $this->assertSame([], $builder->getBindings());
    }

    public function testWhereNotMorphedTo()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->whereNotMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from "model_parent_stubs" where not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals([$relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereNotMorphedToCollection()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelCloseRelatedStub;
        $secondRelatedModel->id = 2;

        $builder = $model->whereNotMorphedTo('morph', new Collection([$firstRelatedModel, $secondRelatedModel]));

        $this->assertSame('select * from "model_parent_stubs" where not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?, ?)))', $builder->toSql());
        $this->assertEquals([$firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $secondRelatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereNotMorphedToCollectionWithDifferentModels()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelFarRelatedStub;
        $secondRelatedModel->id = 2;

        $thirdRelatedModel = new ModelCloseRelatedStub;
        $thirdRelatedModel->id = 3;

        $builder = $model->whereNotMorphedTo('morph', [$firstRelatedModel, $secondRelatedModel, $thirdRelatedModel]);

        $this->assertSame('select * from "model_parent_stubs" where not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?, ?)) or ("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals([$firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $thirdRelatedModel->getKey(), $secondRelatedModel->getMorphClass(), $secondRelatedModel->id], $builder->getBindings());
    }

    public function testOrWhereMorphedTo()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->where('bar', 'baz')->orWhereMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals(['baz', $relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testOrWhereMorphedToCollection()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelCloseRelatedStub;
        $secondRelatedModel->id = 2;

        $builder = $model->where('bar', 'baz')->orWhereMorphedTo('morph', new Collection([$firstRelatedModel, $secondRelatedModel]));

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?, ?)))', $builder->toSql());
        $this->assertEquals(['baz', $firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $secondRelatedModel->getKey()], $builder->getBindings());
    }

    public function testOrWhereMorphedToCollectionWithDifferentModels()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelFarRelatedStub;
        $secondRelatedModel->id = 2;

        $thirdRelatedModel = new ModelCloseRelatedStub;
        $thirdRelatedModel->id = 3;

        $builder = $model->where('bar', 'baz')->orWhereMorphedTo('morph', [$firstRelatedModel, $secondRelatedModel, $thirdRelatedModel]);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or (("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?, ?)) or ("model_parent_stubs"."morph_type" = ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals(['baz', $firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $thirdRelatedModel->getKey(), $secondRelatedModel->getMorphClass(), $secondRelatedModel->id], $builder->getBindings());
    }

    public function testOrWhereMorphedToNull()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->where('bar', 'baz')->orWhereMorphedTo('morph', null);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or "model_parent_stubs"."morph_type" is null', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());
    }

    public function testOrWhereNotMorphedTo()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->where('bar', 'baz')->orWhereNotMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals(['baz', $relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testOrWhereNotMorphedToCollection()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelCloseRelatedStub;
        $secondRelatedModel->id = 2;

        $builder = $model->where('bar', 'baz')->orWhereNotMorphedTo('morph', new Collection([$firstRelatedModel, $secondRelatedModel]));

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?, ?)))', $builder->toSql());
        $this->assertEquals(['baz', $firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $secondRelatedModel->getKey()], $builder->getBindings());
    }

    public function testOrWhereNotMorphedToCollectionWithDifferentModels()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $firstRelatedModel = new ModelCloseRelatedStub;
        $firstRelatedModel->id = 1;

        $secondRelatedModel = new ModelFarRelatedStub;
        $secondRelatedModel->id = 2;

        $thirdRelatedModel = new ModelCloseRelatedStub;
        $thirdRelatedModel->id = 3;

        $builder = $model->where('bar', 'baz')->orWhereNotMorphedTo('morph', [$firstRelatedModel, $secondRelatedModel, $thirdRelatedModel]);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?, ?)) or ("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals(['baz', $firstRelatedModel->getMorphClass(), $firstRelatedModel->getKey(), $thirdRelatedModel->getKey(), $secondRelatedModel->getMorphClass(), $secondRelatedModel->id], $builder->getBindings());
    }

    public function testWhereMorphedToClass()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->whereMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where "model_parent_stubs"."morph_type" = ?', $builder->toSql());
        $this->assertEquals([ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testWhereNotMorphedToClass()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where not ("model_parent_stubs"."morph_type" is not distinct from ?)', $builder->toSql());
        $this->assertEquals([ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testOrWhereMorphedToClass()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->where('bar', 'baz')->orWhereMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or "model_parent_stubs"."morph_type" = ?', $builder->toSql());
        $this->assertEquals(['baz', ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testOrWhereNotMorphedToClass()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        $builder = $model->where('bar', 'baz')->orWhereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where "bar" = ? or not ("model_parent_stubs"."morph_type" is not distinct from ?)', $builder->toSql());
        $this->assertEquals(['baz', ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testWhereNotMorphedToWithSQLite()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'SQLite');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->whereNotMorphedTo('morph', $relatedModel);

        $this->assertStringNotContainsString('<=>', $builder->toSql());
        $this->assertSame('select * from "model_parent_stubs" where not (("model_parent_stubs"."morph_type" is ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertEquals([$relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereNotMorphedToClassWithSQLite()
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'SQLite');

        $builder = $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertStringNotContainsString('<=>', $builder->toSql());
        $this->assertSame('select * from "model_parent_stubs" where not ("model_parent_stubs"."morph_type" is ?)', $builder->toSql());
        $this->assertEquals([ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testWhereNotMorphedToWithMySQL(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'MySql');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->whereNotMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from `model_parent_stubs` where not ((`model_parent_stubs`.`morph_type` <=> ? and `model_parent_stubs`.`morph_id` in (?)))', $builder->toSql());
        $this->assertSame([$relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereNotMorphedToClassWithMySQL(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'MySql');

        $builder = $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from `model_parent_stubs` where not (`model_parent_stubs`.`morph_type` <=> ?)', $builder->toSql());
        $this->assertSame([ModelCloseRelatedStub::class], $builder->getBindings());
    }

    public function testWhereNotMorphedToWithPostgres(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'Postgres');

        $relatedModel = new ModelCloseRelatedStub;
        $relatedModel->id = 1;

        $builder = $model->whereNotMorphedTo('morph', $relatedModel);

        $this->assertSame('select * from "model_parent_stubs" where not (("model_parent_stubs"."morph_type" is not distinct from ? and "model_parent_stubs"."morph_id" in (?)))', $builder->toSql());
        $this->assertSame([$relatedModel->getMorphClass(), $relatedModel->getKey()], $builder->getBindings());
    }

    public function testWhereNotMorphedToClassWithPostgres(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, 'Postgres');

        $builder = $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where not ("model_parent_stubs"."morph_type" is not distinct from ?)', $builder->toSql());
        $this->assertSame([ModelCloseRelatedStub::class], $builder->getBindings());
    }

    // REMOVED: SQL Server whereNotMorphedTo tests; SQL Server is not supported.

    public function testWhereMorphedToAlias(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::enforceMorphMap([
            'alias' => ModelCloseRelatedStub::class,
        ]);

        $builder = $model->whereMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame('select * from "model_parent_stubs" where "model_parent_stubs"."morph_type" = ?', $builder->toSql());
        $this->assertSame(['alias'], $builder->getBindings());
    }

    public function testWhereMorphedToAcceptsStoredAliasesWhenMorphMapIsRequired(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::enforceMorphMap([
            ModelCloseRelatedStub::class => ModelFarRelatedStub::class,
        ]);

        $classAliasBuilder = $model->whereMorphedTo('morph', ModelCloseRelatedStub::class);
        $plainAliasBuilder = $model->whereMorphedTo('morph', 'legacy-alias');

        $this->assertSame([ModelCloseRelatedStub::class], $classAliasBuilder->getBindings());
        $this->assertSame(['legacy-alias'], $plainAliasBuilder->getBindings());
    }

    public function testWhereMorphedToClassRequiresMorphMap(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::requireMorphMap();

        $this->expectException(ClassMorphViolationException::class);

        $model->whereMorphedTo('morph', ModelCloseRelatedStub::class);
    }

    public function testWhereMorphedToAbstractClassRequiresMorphMap(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::requireMorphMap();

        $this->expectException(ClassMorphViolationException::class);

        $model->whereMorphedTo('morph', AbstractModelRelatedStub::class);
    }

    public function testWhereNotMorphedToClassRequiresMorphMap(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::requireMorphMap();

        $this->expectException(ClassMorphViolationException::class);

        $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);
    }

    public function testWhereMorphedToClassUsesIntegerAliasForBothPolarities(): void
    {
        $model = new ModelParentStub;
        $this->mockConnectionForModel($model, '');

        Relation::morphMap([
            0 => ModelCloseRelatedStub::class,
            2 => ModelFarRelatedStub::class,
        ]);

        $builder = $model->whereMorphedTo('morph', ModelCloseRelatedStub::class);
        $negativeBuilder = $model->whereNotMorphedTo('morph', ModelCloseRelatedStub::class);

        $this->assertSame(['0'], $builder->getBindings());
        $this->assertSame(['0'], $negativeBuilder->getBindings());
    }

    public function testWhereKeyMethodWithInt()
    {
        $model = $this->getMockModel();
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $int = 1;

        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('where')->with($keyName, '=', $int);

        $builder->whereKey($int);
    }

    public function testWhereKeyMethodWithStringZero()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $int = 0;

        $builder->getQuery()->expects('where')->with($keyName, '=', (string) $int);

        $builder->whereKey($int);
    }

    public function testWhereKeyMethodWithStringNull()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('where')->with($keyName, '=', m::on(function ($argument) {
            return $argument === null;
        }));

        $builder->whereKey(null);
    }

    public function testWhereKeyMethodWithArray(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $array = [1, 2, 3];

        $builder->getQuery()->expects('whereIntegerInRaw')->with($keyName, $array);

        $builder->whereKey($array);
    }

    public function testWhereKeyMethodWithCollection(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $collection = new Collection([1, 2, 3]);

        $builder->getQuery()->expects('whereIntegerInRaw')->with($keyName, $collection);

        $builder->whereKey($collection);
    }

    public function testWhereKeyMethodWithModel()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('where')->with($keyName, '=', m::on(function ($argument) {
            return $argument === '1';
        }));

        $builder->whereKey(new class extends Model {
            protected array $attributes = ['id' => 1];
        });
    }

    public function testWhereKeyMethodWithBinaryParameter(): void
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $binary = new BinaryParameter("\0binary-key");

        $builder->getQuery()->expects('where')->with($model->getQualifiedKeyName(), '=', $binary);

        $builder->whereKey($binary);
    }

    public function testWhereKeyMethodKeepsStringableCoercion(): void
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $identifier = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-key';
            }
        };

        $builder->getQuery()->expects('where')->with($model->getQualifiedKeyName(), '=', 'stringable-key');

        $builder->whereKey($identifier);
    }

    public function testWhereKeyNotMethodWithStringZero()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $int = 0;

        $builder->getQuery()->expects('where')->with($keyName, '!=', (string) $int);

        $builder->whereKeyNot($int);
    }

    public function testWhereKeyNotMethodWithStringNull()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('where')->with($keyName, '!=', m::on(function ($argument) {
            return $argument === null;
        }));

        $builder->whereKeyNot(null);
    }

    public function testWhereKeyNotMethodWithInt()
    {
        $model = $this->getMockModel();
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $int = 1;

        $model->expects('getKeyType')->andReturn('int');
        $builder->getQuery()->expects('where')->with($keyName, '!=', $int);

        $builder->whereKeyNot($int);
    }

    public function testWhereKeyNotMethodWithArray(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $array = [1, 2, 3];

        $builder->getQuery()->expects('whereIntegerNotInRaw')->with($keyName, $array);

        $builder->whereKeyNot($array);
    }

    public function testWhereKeyNotMethodWithCollection(): void
    {
        $model = $this->getMockModel();
        $model->expects('getKeyType')->andReturn('int');
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $collection = new Collection([1, 2, 3]);

        $builder->getQuery()->expects('whereIntegerNotInRaw')->with($keyName, $collection);

        $builder->whereKeyNot($collection);
    }

    public function testWhereKeyNotMethodWithModel()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('where')->with($keyName, '!=', m::on(function ($argument) {
            return $argument === '1';
        }));

        $builder->whereKeyNot(new class extends Model {
            protected array $attributes = ['id' => 1];
        });
    }

    public function testWhereKeyNotMethodWithBinaryParameter(): void
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $binary = new BinaryParameter("\0binary-key");

        $builder->getQuery()->expects('where')->with($model->getQualifiedKeyName(), '!=', $binary);

        $builder->whereKeyNot($binary);
    }

    public function testWhereKeyNotMethodKeepsStringableCoercion(): void
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $identifier = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable-key';
            }
        };

        $builder->getQuery()->expects('where')->with($model->getQualifiedKeyName(), '!=', 'stringable-key');

        $builder->whereKeyNot($identifier);
    }

    public function testOrWhereKeyMethodWithInt(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKey(2);

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" = ?)', $query->toSql());
        $this->assertEquals([1, 2], $query->getBindings());
    }

    public function testOrWhereKeyMethodWithArray(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKey([2, 3]);

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" in (2, 3))', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testOrWhereKeyMethodWithCollection(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKey(new Collection([2, 3]));

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" in (2, 3))', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testOrWhereKeyNotMethodWithInt(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKeyNot(2);

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" != ?)', $query->toSql());
        $this->assertEquals([1, 2], $query->getBindings());
    }

    public function testOrWhereKeyNotMethodWithArray(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKeyNot([2, 3]);

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" not in (2, 3))', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testOrWhereKeyNotMethodWithCollection(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKeyNot(new Collection([2, 3]));

        $this->assertSame('select * from "table" where "table"."id" = ? or ("table"."id" not in (2, 3))', $query->toSql());
        $this->assertEquals([1], $query->getBindings());
    }

    public function testOrWhereKeyMethodsHonorWhereKeyOverrides(): void
    {
        $model = new WhereKeyOverrideStub;
        $this->mockConnectionForModel($model, 'SQLite');

        $query = $model->newQuery()->whereKey(1)->orWhereKey(2)->orWhereKeyNot(3);

        $this->assertSame('select * from "table" where ("tenant_id" = ? and "local_id" = ?) or (("tenant_id" = ? and "local_id" = ?)) or (not ("tenant_id" = ? and "local_id" = ?))', $query->toSql());
        $this->assertEquals([1, 1, 2, 2, 3, 3], $query->getBindings());
    }

    public function testExceptMethodWithModel()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('where')->with($keyName, '!=', m::on(function ($argument) {
            return $argument === '1';
        }));

        $builder->except(new class extends Model {
            protected array $attributes = ['id' => 1];
        });
    }

    public function testExceptMethodWithCollectionOfModel()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('whereNotIn')->with($keyName, m::on(function ($argument) {
            return $argument === [1, 2];
        }));

        $models = new Collection([
            new class extends Model {
                protected array $attributes = ['id' => 1];
            },
            new class extends Model {
                protected array $attributes = ['id' => 2];
            },
        ]);

        $builder->except($models);
    }

    public function testExceptMethodWithArrayOfModel()
    {
        $model = new StubStringPrimaryKey;
        $builder = $this->getBuilder()->setModel($model);
        $keyName = $model->getQualifiedKeyName();

        $builder->getQuery()->expects('whereNotIn')->with($keyName, m::on(function ($argument) {
            return $argument === [1, 2];
        }));

        $models = [
            new class extends Model {
                protected array $attributes = ['id' => 1];
            },
            new class extends Model {
                protected array $attributes = ['id' => 2];
            },
        ];

        $builder->except($models);
    }

    public function testWhereIn()
    {
        $model = new NestedStub;
        $this->mockConnectionForModel($model, '');
        $query = $model->newQuery()->withoutGlobalScopes()->whereIn('foo', $model->newQuery()->select('id'));
        $expected = 'select * from "table" where "foo" in (select "id" from "table" where ("table"."deleted_at" is null))';
        $this->assertEquals($expected, $query->toSql());
    }

    public function testLatestWithoutColumnWithCreatedAt(): void
    {
        $model = $this->getMockModel();
        $model->expects('getCreatedAtColumn')->andReturn('foo');
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('latest')->with('foo');

        $builder->latest();
    }

    public function testLatestWithoutColumnWithoutCreatedAt(): void
    {
        $model = $this->getMockModel();
        $model->expects('getCreatedAtColumn')->andReturn(null);
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('latest')->with('created_at');

        $builder->latest();
    }

    public function testLatestWithColumn()
    {
        $model = $this->getMockModel();
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('latest')->with('foo');

        $builder->latest('foo');
    }

    public function testLatestAndOldestAcceptQueryableSubqueries(): void
    {
        $model = new ModelParentStub;
        $model->foo_id = 7;
        $this->mockConnectionForModel($model, 'SQLite');

        $latest = $model->newQuery()->latest($model->foo());

        $this->assertSame(
            'select * from "model_parent_stubs" order by (select * from "model_close_related_stubs" where "model_close_related_stubs"."id" = ?) desc',
            $latest->toSql()
        );
        $this->assertSame([7], $latest->getBindings());

        $subquery = $model->foo()->getRelated()->newQuery()
            ->select('score')
            ->where('active', true);
        $oldest = $model->newQuery()->oldest($subquery);

        $this->assertSame(
            'select * from "model_parent_stubs" order by (select "score" from "model_close_related_stubs" where "active" = ?) asc',
            $oldest->toSql()
        );
        $this->assertSame([true], $oldest->getBindings());
    }

    public function testOldestWithoutColumnWithCreatedAt(): void
    {
        $model = $this->getMockModel();
        $model->expects('getCreatedAtColumn')->andReturn('foo');
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('oldest')->with('foo');

        $builder->oldest();
    }

    public function testOldestWithoutColumnWithoutCreatedAt(): void
    {
        $model = $this->getMockModel();
        $model->expects('getCreatedAtColumn')->andReturn(null);
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('oldest')->with('created_at');

        $builder->oldest();
    }

    public function testOldestWithColumn()
    {
        $model = $this->getMockModel();
        $builder = $this->getBuilder()->setModel($model);

        $builder->getQuery()->expects('oldest')->with('foo');

        $builder->oldest('foo');
    }

    public function testUpdate()
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" set "foo" = ?, "table"."updated_at" = ?', ['bar', $now])->andReturn(1);

        $result = $builder->update(['foo' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithTimestampValue(): void
    {
        $connection = m::mock(Connection::class);
        $connection->expects('getTablePrefix')->times(2)->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" set "foo" = ?, "table"."updated_at" = ?', ['bar', null])->andReturn(1);

        $result = $builder->update(['foo' => 'bar', 'updated_at' => null]);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithQualifiedTimestampValue()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" set "table"."foo" = ?, "table"."updated_at" = ?', ['bar', null])->andReturn(1);

        $result = $builder->update(['table.foo' => 'bar', 'table.updated_at' => null]);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithoutTimestamp(): void
    {
        $connection = m::mock(Connection::class);
        $connection->expects('getTablePrefix')->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new StubWithoutTimestamp;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" set "foo" = ?', ['bar'])->andReturn(1);

        $result = $builder->update(['foo' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithAlias()
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" as "alias" set "foo" = ?, "alias"."updated_at" = ?', ['bar', $now])->andReturn(1);

        $result = $builder->from('table as alias')->update(['foo' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithAliasWithQualifiedTimestampValue(): void
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder->setModel($model);
        $builder->getConnection()->expects('update')
            ->with('update "table" as "alias" set "foo" = ?, "alias"."updated_at" = ?', ['bar', null])->andReturn(1);

        $result = $builder->from('table as alias')->update(['foo' => 'bar', 'alias.updated_at' => null]);
        $this->assertEquals(1, $result);
    }

    public function testUpdateWithAnExplicitRawSourceAliasAndPrefix(): void
    {
        CarbonImmutable::setTestNow('2017-10-10 10:10:10');

        $connection = m::mock(Connection::class, ['getTablePrefix' => 'prefix_']);
        $query = new BaseBuilder($connection, new MySqlGrammar($connection), m::mock(Processor::class));
        $builder = (new Builder($query))->setModel((new Stub)->setDateFormat('Y-m-d H:i:s'));
        $connection->expects('update')->with(
            'update `prefix_table` as `prefix_target` inner join `prefix_profiles` on `prefix_profiles`.`user_id` = `prefix_target`.`id` set `prefix_target`.`name` = ?, `prefix_target`.`updated_at` = ?',
            ['new', '2017-10-10 10:10:10'],
        )->andReturn(1);

        $builder->from(new Expression('`prefix_table`'), 'target')
            ->join('profiles', 'profiles.user_id', '=', 'target.id');

        $this->assertSame(1, $builder->update(['target.name' => 'new']));
        $this->assertSame('target', $builder->getFromAlias());
    }

    public function testUpdateOrInsertReturnsTheQueryResult(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('updateOrInsert')
            ->with(['email' => 'user@example.com'], ['name' => 'Taylor'])->andReturn(false);

        $this->assertFalse($builder->updateOrInsert(['email' => 'user@example.com'], ['name' => 'Taylor']));
    }

    public function testUpdateOrInsertAppliesGlobalScopes(): void
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $connection->statement('create table items (id integer primary key, email text, name text, active integer)');
        $connection->table('items')->insert(['email' => 'user@example.com', 'name' => 'old', 'active' => 0]);
        $builder = (new Builder($connection->query()))->setModel((new Stub)->setTable('items'));
        $builder->withGlobalScope('active', fn (Builder $query): Builder => $query->where('active', 1));

        $this->assertTrue($builder->updateOrInsert(['email' => 'user@example.com'], ['name' => 'new', 'active' => 1]));
        $this->assertSame(['old', 'new'], $connection->table('items')->orderBy('id')->pluck('name')->all());
    }

    #[DataProvider('updateFromTimestamps')]
    public function testUpdateFromAppliesScopesAndModelTimestamps(bool $timestamps, array $values, string $sql, array $bindings): void
    {
        CarbonImmutable::setTestNow('2017-10-10 10:10:10');
        $model = new Stub;
        $model->timestamps = $timestamps;
        $connection = $this->mockConnectionForModel($model, 'Postgres');
        $connection->expects('update')->with($sql, $bindings)->andReturn(2);

        $builder = $model->newQuery()
            ->withGlobalScope('active', fn (Builder $query): Builder => $query->where('table.active', 1))
            ->join('profiles', 'profiles.user_id', '=', 'table.id');

        $this->assertSame(2, $builder->updateFrom($values));
    }

    /**
     * Provide timestamp configurations for PostgreSQL model updates.
     */
    public static function updateFromTimestamps(): array
    {
        return [
            'automatic timestamp' => [
                true,
                ['name' => 'new'],
                'update "table" set "name" = ?, "updated_at" = ? from "profiles" where ("table"."active" = ?) and "profiles"."user_id" = "table"."id"',
                ['new', '2017-10-10 10:10:10', 1],
            ],
            'explicit timestamp' => [
                true,
                ['name' => 'new', 'updated_at' => null],
                'update "table" set "name" = ?, "updated_at" = ? from "profiles" where ("table"."active" = ?) and "profiles"."user_id" = "table"."id"',
                ['new', null, 1],
            ],
            'timestamps disabled' => [
                false,
                ['name' => 'new'],
                'update "table" set "name" = ? from "profiles" where ("table"."active" = ?) and "profiles"."user_id" = "table"."id"',
                ['new', 1],
            ],
        ];
    }

    #[DataProvider('rawSourceUpdateValues')]
    public function testUpdateFromWithAnOpaqueRawSourcePreservesSuppliedValues(array $values, string $columns, array $bindings): void
    {
        $model = new Stub;
        $connection = $this->mockConnectionForModel($model, 'Postgres');
        $connection->expects('update')->with(
            'update "table" as "target" set ' . $columns . ' from "profiles" where "profiles"."user_id" = "target"."id"',
            $bindings,
        )->andReturn(1);

        $builder = $model->newQuery()->fromRaw('"table" as "target"')
            ->join('profiles', 'profiles.user_id', '=', 'target.id');

        $this->assertSame(1, $builder->updateFrom($values));
    }

    /**
     * Provide writes with caller-owned timestamps for opaque raw sources.
     */
    public static function rawSourceUpdateValues(): array
    {
        return [
            'no automatic timestamp' => [['name' => 'new'], '"name" = ?', ['new']],
            'explicit qualified timestamp' => [['name' => 'new', 'target.updated_at' => null], '"name" = ?, "updated_at" = ?', ['new', null]],
        ];
    }

    public function testGetColumnsReturnsTheQueryColumns(): void
    {
        $builder = $this->getBuilder();
        $builder->getQuery()->expects('getColumns')->andReturn(['name']);

        $this->assertSame(['name'], $builder->getColumns());
    }

    public function testGetProcessorReturnsTheQueryProcessor(): void
    {
        $builder = $this->getBuilder();
        $processor = new Processor;
        $builder->getQuery()->expects('getProcessor')->andReturn($processor);

        $this->assertSame($processor, $builder->getProcessor());
    }

    public function testUpsert(): void
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table')->andReturnSelf();
        $query->from = 'foo_table';

        $builder = new Builder($query);
        $model = new StubStringPrimaryKey;
        $builder->setModel($model);

        $query->expects('upsert')
            ->with([
                ['email' => 'foo', 'name' => 'bar', 'updated_at' => $now, 'created_at' => $now],
                ['name' => 'bar2', 'email' => 'foo2', 'updated_at' => $now, 'created_at' => $now],
            ], ['email'], ['email', 'name', 'updated_at'])->andReturn(2);

        $result = $builder->upsert([['email' => 'foo', 'name' => 'bar'], ['name' => 'bar2', 'email' => 'foo2']], ['email']);

        $this->assertEquals(2, $result);
    }

    public function testTouch(): void
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table')->andReturnSelf();
        $query->from = 'foo_table';

        $builder = new Builder($query);
        $model = new StubStringPrimaryKey;
        $builder->setModel($model);

        $query->expects('update')->with(['updated_at' => $now])->andReturn(2);

        $result = $builder->touch();

        $this->assertEquals(2, $result);
    }

    public function testTouchWithCustomColumn(): void
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table')->andReturnSelf();
        $query->from = 'foo_table';

        $builder = new Builder($query);
        $model = new StubStringPrimaryKey;
        $builder->setModel($model);

        $query->expects('update')->with(['published_at' => $now])->andReturn(2);

        $result = $builder->touch('published_at');

        $this->assertEquals(2, $result);
    }

    public function testTouchWithMultipleColumns(): void
    {
        CarbonImmutable::setTestNow($now = '2017-10-10 10:10:10');

        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table')->andReturnSelf();
        $query->from = 'foo_table';

        $builder = new Builder($query);
        $model = new StubStringPrimaryKey;
        $builder->setModel($model);

        $query->expects('update')
            ->with(['published_at' => $now, 'verified_at' => $now])
            ->andReturn(2);

        $result = $builder->touch(['published_at', 'verified_at']);

        $this->assertSame(2, $result);
    }

    public function testTouchWithoutUpdatedAtColumn(): void
    {
        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('table')->andReturnSelf();
        $query->from = 'table';

        $builder = new Builder($query);
        $model = new StubWithoutTimestamp;
        $builder->setModel($model);

        $query->shouldNotReceive('update');

        $result = $builder->touch();

        $this->assertFalse($result);
    }

    public function testWithCastsMethod(): void
    {
        $builder = new Builder($this->getMockQueryBuilder());
        $model = $this->getMockModel();
        $builder->setModel($model);

        $model->expects('mergeCasts')->with(['foo' => 'bar']);
        $builder->withCasts(['foo' => 'bar']);
    }

    public function testClone(): void
    {
        $connection = m::mock(Connection::class);
        $connection->expects('getTablePrefix')->times(2)->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = new Builder($query);
        $builder->select('*')->from('users');
        $clone = $builder->clone()->where('email', 'foo');

        $this->assertNotSame($builder, $clone);
        $this->assertSame('select * from "users"', $builder->toSql());
        $this->assertSame('select * from "users" where "email" = ?', $clone->toSql());
    }

    public function testCloneWithoutPreservesTheModelAndCloneCallbackChanges(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder = $model->newQuery()->where('active', 1)->orderBy('name');
        $clones = [];
        $builder->onClone(function (Builder $clone) use (&$clones): void {
            $clones[] = $clone;
            $clone->where('verified', 1)->orderBy('id');
        });

        $clone = $builder->cloneWithout(['orders']);

        $this->assertNotSame($builder, $clone);
        $this->assertSame($model, $clone->getModel());
        $this->assertSame([$clone], $clones);
        $this->assertSame('select * from "table" where "active" = ? and "verified" = ?', $clone->toSql());
        $this->assertSame('select * from "table" where "active" = ? order by "name" asc', $builder->toSql());
    }

    public function testCloneWithoutBindingsKeepsTheOriginalQueryIntact(): void
    {
        $model = new Stub;
        $this->mockConnectionForModel($model, '');
        $builder = $model->newQuery()->where('active', 1)->orderBy('name');

        $withoutWheres = $builder->cloneWithout(['wheres']);
        $clone = $withoutWheres->cloneWithoutBindings(['where']);

        $this->assertNotSame($withoutWheres, $clone);
        $this->assertSame($model, $clone->getModel());
        $this->assertSame('select * from "table" order by "name" asc', $clone->toSql());
        $this->assertSame([], $clone->getBindings());
        $this->assertSame([1], $withoutWheres->getBindings());
        $this->assertSame([1], $builder->getBindings());
        $this->assertSame('select * from "table" where "active" = ? order by "name" asc', $builder->toSql());
    }

    public function testNewQueryRestoresTheModelDefaults(): void
    {
        $model = new FreshQueryModel;
        $this->mockConnectionForModel($model, '');
        FreshQueryModel::addGlobalScope('active', fn (Builder $query): Builder => $query->where('active', 1));
        $builder = $model->newQuery()->withoutGlobalScopes()->withoutEagerLoads()->where('name', 'Taylor');
        $builder->macro('customQuery', fn (Builder $query): Builder => $query);

        $fresh = $builder->newQuery();

        $this->assertNotSame($builder, $fresh);
        $this->assertSame($model, $fresh->getModel());
        $this->assertInstanceOf(FreshQueryBuilder::class, $fresh);
        $this->assertSame(['related'], array_keys($fresh->getEagerLoads()));
        $this->assertFalse($fresh->hasMacro('customQuery'));
        $this->assertSame('select * from "table" where ("active" = ?)', $fresh->toSql());
        $this->assertSame('select * from "table" where "name" = ?', $builder->toSql());
    }

    public function testCloneModelMakesAFreshCopyOfTheModel(): void
    {
        $connection = m::mock(Connection::class);
        $connection->expects('getTablePrefix')->times(2)->andReturn('');
        $query = new BaseBuilder($connection, new Grammar($connection), m::mock(Processor::class));
        $builder = (new Builder($query))->setModel(new Stub);
        $builder->select('*')->from('users');

        $onCloneCallbackCalledCount = 0;

        $onCloneQuery = null;

        $builder->onClone(function (Builder $query) use (&$onCloneCallbackCalledCount, &$onCloneQuery): void {
            ++$onCloneCallbackCalledCount;

            $onCloneQuery = $query;
        });

        $clone = $builder->clone()->where('email', 'foo');

        $this->assertNotSame($builder, $clone);
        $this->assertSame('select * from "users"', $builder->toSql());
        $this->assertSame('select * from "users" where "email" = ?', $clone->toSql());

        $this->assertSame(1, $onCloneCallbackCalledCount);
        $this->assertSame($onCloneQuery, $clone);
    }

    public function testToRawSql(): void
    {
        $query = m::mock(BaseBuilder::class);
        $query->expects('toRawSql')
            ->andReturn('select * from "users" where "email" = \'foo\'');

        $builder = new Builder($query);

        $this->assertSame('select * from "users" where "email" = \'foo\'', $builder->toRawSql());
    }

    public function testPassthruMethodsCallsAreNotCaseSensitive(): void
    {
        $query = m::mock(BaseBuilder::class);

        $mockResponse = 'select 1';
        $query
            ->expects('toRawSql')
            ->andReturn($mockResponse)
            ->times(3);

        $builder = new Builder($query);

        $this->assertSame('select 1', $builder->TORAWSQL());
        $this->assertSame('select 1', $builder->toRawSql());
        $this->assertSame('select 1', $builder->toRawSQL());
    }

    public function testPassthruArrayElementsMustAllBeLowercase()
    {
        $builder = new class(m::mock(BaseBuilder::class)) extends Builder {
            // expose protected member for test
            public function getPassthru(): array
            {
                return $this->passthru;
            }
        };

        $passthru = $builder->getPassthru();

        foreach ($passthru as $method) {
            $lowercaseMethod = strtolower($method);

            $this->assertSame(
                $lowercaseMethod,
                $method,
                'Eloquent\Builder relies on lowercase method names in $passthru array to correctly mimic PHP case-insensitivity on method dispatch.'
                    . 'If you are adding a new method to the $passthru array, make sure the name is lowercased.'
            );
        }
    }

    public function testPipeCallback()
    {
        $query = new Builder(new BaseBuilder(
            $connection = new PdoConnection(new PDO('sqlite::memory:')),
            new Grammar($connection),
            new Processor,
        ));

        $result = $query->pipe(fn (Builder $query) => 5);
        $this->assertSame(5, $result);

        $result = $query->pipe(fn (Builder $query) => null);
        $this->assertSame($query, $result);

        $result = $query->pipe(function (Builder $query) {
        });
        $this->assertSame($query, $result);

        $this->assertCount(0, $query->getQuery()->wheres);
        $result = $query->pipe(fn (Builder $query) => $query->where('foo', 'bar'));
        $this->assertSame($query, $result);
        $this->assertCount(1, $query->getQuery()->wheres);
    }

    public function testIncrementEachCallsToBaseWithUpdatedAt(): void
    {
        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table');
        $query->expects('getFromAlias')->andReturn('foo_table');
        $query->expects('incrementEach')->withArgs(function (array $columns, array $extra): bool {
            return $columns === ['votes' => 5]
                && array_key_exists('foo_table.updated_at', $extra);
        })->andReturn(1);

        $builder = new Builder($query);
        $model = $this->getMockModel();
        $model->expects('usesTimestamps')->andReturn(true);
        $model->expects('getUpdatedAtColumn')->times(2)->andReturn('updated_at');
        $model->expects('freshTimestampString')->andReturn('2026-03-26 00:00:00');
        $model->expects('hasSetMutator')->andReturn(false);
        $model->expects('hasAttributeSetMutator')->andReturn(false);
        $model->expects('hasCast')->andReturn(false);
        $builder->setModel($model);

        $result = $builder->incrementEach(['votes' => 5]);

        $this->assertSame(1, $result);
    }

    public function testDecrementEachCallsToBaseWithUpdatedAt(): void
    {
        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table');
        $query->expects('getFromAlias')->andReturn('foo_table');
        $query->expects('decrementEach')->withArgs(function (array $columns, array $extra): bool {
            return $columns === ['votes' => 3]
                && array_key_exists('foo_table.updated_at', $extra);
        })->andReturn(1);

        $builder = new Builder($query);
        $model = $this->getMockModel();
        $model->expects('usesTimestamps')->andReturn(true);
        $model->expects('getUpdatedAtColumn')->times(2)->andReturn('updated_at');
        $model->expects('freshTimestampString')->andReturn('2026-03-26 00:00:00');
        $model->expects('hasSetMutator')->andReturn(false);
        $model->expects('hasAttributeSetMutator')->andReturn(false);
        $model->expects('hasCast')->andReturn(false);
        $builder->setModel($model);

        $result = $builder->decrementEach(['votes' => 3]);

        $this->assertSame(1, $result);
    }

    public function testIncrementEachWithoutTimestamps(): void
    {
        $query = m::mock(BaseBuilder::class);
        $query->expects('from')->with('foo_table');
        $query->expects('incrementEach')->with(['votes' => 1], [])->andReturn(1);

        $builder = new Builder($query);
        $model = $this->getMockModel();
        $model->expects('usesTimestamps')->andReturn(false);
        $builder->setModel($model);

        $result = $builder->incrementEach(['votes' => 1]);

        $this->assertSame(1, $result);
    }

    /**
     * Assert that a relationship constraint timeout is rejected before it is embedded.
     */
    protected function assertRelationshipConstraintTimeoutRejected(Closure $accept): void
    {
        $builder = (new ModelParentStub)->newQuery()->where('tenant_id', 7);
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        try {
            $accept($builder);
            $this->fail('Expected the relationship constraint timeout to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'A relationship constraint cannot define its own query timeout. Apply the timeout to the outer query instead.',
                $exception->getMessage()
            );
        }

        $this->assertSame($sql, $builder->toSql());
        $this->assertSame($bindings, $builder->getBindings());
    }

    protected function mockConnectionForModel($model, $database)
    {
        $grammarClass = 'Hypervel\Database\Query\Grammars\\' . $database . 'Grammar';
        $processorClass = 'Hypervel\Database\Query\Processors\\' . $database . 'Processor';
        $processor = new $processorClass;
        $connection = m::mock(Connection::class, ['getPostProcessor' => $processor]);
        $grammar = new $grammarClass($connection);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('query')->andReturnUsing(function () use ($connection, $grammar, $processor) {
            return new BaseBuilder($connection, $grammar, $processor);
        });
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $resolver = m::mock(ConnectionResolverInterface::class, ['connection' => $connection]);
        $class = get_class($model);
        $class::setConnectionResolver($resolver);

        return $connection;
    }

    protected function getBuilder()
    {
        return new Builder($this->getMockQueryBuilder());
    }

    protected function getMockModel()
    {
        $model = m::mock(Model::class);
        $model->shouldReceive('getKeyName')->andReturn('foo');
        $model->shouldReceive('getTable')->andReturn('foo_table');
        $model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');

        return $model;
    }

    protected function getMockQueryBuilder()
    {
        $query = m::mock(BaseBuilder::class);
        $query->shouldReceive('from')->with('foo_table');

        return $query;
    }
}

class Stub extends Model
{
    protected ?string $table = 'table';
}

class FreshQueryBuilder extends Builder
{
}

class FreshQueryModel extends Stub
{
    protected static string $builder = FreshQueryBuilder::class;

    protected array $with = ['related'];
}

class ScopeStub extends Model
{
    public function scopeApproved($query)
    {
        $query->where('foo', 'bar');
    }
}

class DynamicScopeStub extends Model
{
    public function scopeDynamic($query, $foo = 'foo', $bar = 'bar')
    {
        $query->where($foo, $bar);
    }
}

class HigherOrderWhereScopeStub extends Model
{
    protected ?string $table = 'table';

    public function scopeOne($query)
    {
        $query->where('one', 'foo');
    }

    public function scopeTwo($query)
    {
        $query->where('two', 'bar');
    }

    public function scopeThree($query)
    {
        $query->where('three', 'baz');
    }
}

class NestedStub extends Model
{
    use SoftDeletes;

    protected ?string $table = 'table';

    public function scopeEmpty($query)
    {
        return $query;
    }
}

class PluckStub extends Model
{
    /**
     * Get the decorated attribute value.
     */
    public function getAttribute(string $key): mixed
    {
        return 'foo_' . $this->attributes[$key];
    }
}

class PluckDatesStub extends Model
{
    /**
     * Get the decorated date attribute value.
     */
    public function getAttribute(string $key): mixed
    {
        return 'date_' . $this->attributes[$key];
    }
}

class ModelParentStub extends Model
{
    public function foo()
    {
        return $this->belongsTo(ModelCloseRelatedStub::class);
    }

    public function address()
    {
        return $this->belongsTo(ModelCloseRelatedStub::class, 'foo_id');
    }

    public function activeFoo()
    {
        return $this->belongsTo(ModelCloseRelatedStub::class, 'foo_id')->where('active', true);
    }

    public function roles()
    {
        return $this->belongsToMany(
            ModelFarRelatedStub::class,
            'user_role',
            'self_id',
            'related_id'
        );
    }

    public function morph()
    {
        return $this->morphTo();
    }
}

class ModelCloseRelatedStub extends Model
{
    public function bar()
    {
        return $this->hasMany(ModelFarRelatedStub::class);
    }

    public function baz()
    {
        return $this->hasMany(ModelFarRelatedStub::class);
    }

    public function bam()
    {
        return $this->hasMany(ModelOtherFarRelatedStub::class);
    }
}

abstract class AbstractModelRelatedStub extends Model
{
}

class ModelFarRelatedStub extends Model
{
    public function roles()
    {
        return $this->belongsToMany(
            ModelParentStub::class,
            'user_role',
            'related_id',
            'self_id',
        );
    }

    public function baz()
    {
        return $this->belongsTo(ModelCloseRelatedStub::class);
    }
}

class ModelOtherFarRelatedStub extends Model
{
    public function roles()
    {
        return $this->belongsToMany(
            ModelParentStub::class,
            'user_role',
            'related_id',
            'self_id',
        );
    }

    public function baz()
    {
        return $this->belongsTo(ModelCloseRelatedStub::class);
    }
}

class ModelSelfRelatedStub extends Model
{
    protected ?string $table = 'self_related_stubs';

    public function parentFoo()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id', 'parent');
    }

    public function childFoo()
    {
        return $this->hasOne(self::class, 'parent_id', 'id');
    }

    public function childFoos()
    {
        return $this->hasMany(self::class, 'parent_id', 'id', 'children');
    }

    public function parentBars()
    {
        return $this->belongsToMany(self::class, 'self_pivot', 'child_id', 'parent_id', 'parent_bars');
    }

    public function childBars()
    {
        return $this->belongsToMany(self::class, 'self_pivot', 'parent_id', 'child_id', 'child_bars');
    }

    public function bazes()
    {
        return $this->hasMany(ModelFarRelatedStub::class, 'foreign_key', 'id', 'bar');
    }
}

class StubWithoutTimestamp extends Model
{
    public const ?string UPDATED_AT = null;

    protected ?string $table = 'table';
}

class StubStringPrimaryKey extends Model
{
    public bool $incrementing = false;

    protected ?string $table = 'foo_table';

    protected string $keyType = 'string';
}

class WhereKeyOverrideStub extends Model
{
    protected ?string $table = 'table';

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder(BaseBuilder $query): Builder
    {
        return new WhereKeyOverrideBuilder($query);
    }
}

class WhereKeyOverrideBuilder extends Builder
{
    /**
     * Add a where clause on both key columns.
     */
    public function whereKey(mixed $id): static
    {
        return $this->where(fn (Builder $query): Builder => $query->where('tenant_id', '=', $id)->where('local_id', '=', $id));
    }

    /**
     * Add a where not clause on both key columns.
     */
    public function whereKeyNot(mixed $id): static
    {
        return $this->whereNot(fn (Builder $query): Builder => $query->where('tenant_id', '=', $id)->where('local_id', '=', $id));
    }
}

enum BuilderTestBackedEnum: string
{
    case Bar = 'bar';
}

enum BuilderTestUnitEnum
{
    case Baz;
}

class WhereBelongsToStub extends Model
{
    protected array $fillable = [
        'id',
        'parent_id',
    ];

    public function whereBelongsToStub()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id', 'parent');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id', 'parent');
    }
}
