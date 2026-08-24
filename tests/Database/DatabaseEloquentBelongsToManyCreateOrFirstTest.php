<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToManyCreateOrFirstTest;

use Closure;
use Exception;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentBelongsToManyCreateOrFirstTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2023-01-01 00:00:00');
    }

    #[DataProvider('createOrFirstValues')]
    public function testCreateOrFirstMethodCreatesNewRelated(Closure|array $values): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
            [456],
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()->expects('insert')->with(
            'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)',
            ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'],
        )->andReturnTrue();

        $source->getConnection()->expects('insert')->with(
            'insert into "pivot_table" ("related_id", "source_id") values (?, ?)',
            [456, 123],
        )->andReturnTrue();

        $result = $source->related()->createOrFirst(['attr' => 'foo'], $values);
        $this->assertTrue($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public static function createOrFirstValues(): array
    {
        return [
            'array' => [['val' => 'bar']],
            'closure' => [fn () => ['val' => 'bar']],
        ];
    }

    public function testCreateOrFirstMethodAssociatesExistingRelated(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $sql = 'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)';
        $bindings = ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'];

        $source->getConnection()
            ->expects('insert')
            ->with($sql, $bindings)
            ->andThrow(new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception));

        $source->getConnection()
            ->expects('select')
            ->with('select * from "related_table" where ("attr" = ?) limit 1', ['foo'], false, [])
            ->andReturn([[
                'id' => 456,
                'attr' => 'foo',
                'val' => 'bar',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00',
            ]]);

        $source->getConnection()->expects('insert')->with(
            'insert into "pivot_table" ("related_id", "source_id") values (?, ?)',
            [456, 123],
        )->andReturnTrue();

        $result = $source->related()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            // Pivot is not loaded when related model is newly created.
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testFirstOrCreateMethodRetrievesExistingRelatedAlreadyAssociated(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()
            ->expects('select')
            ->with(
                'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
                [123, 'foo'],
                true,
                [],
            )
            ->andReturn([[
                'id' => 456,
                'attr' => 'foo',
                'val' => 'bar',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00',
                'pivot_source_id' => 123,
                'pivot_related_id' => 456,
            ]]);

        $result = $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
            'pivot' => [
                'source_id' => 123,
                'related_id' => 456,
            ],
        ], $result->toArray());
    }

    public function testCreateOrFirstMethodRetrievesExistingRelatedAssociatedJustNow(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $sql = 'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)';
        $bindings = ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'];

        $source->getConnection()
            ->expects('insert')
            ->with($sql, $bindings)
            ->andThrow(new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception));

        $source->getConnection()
            ->expects('select')
            ->with('select * from "related_table" where ("attr" = ?) limit 1', ['foo'], false, [])
            ->andReturn([[
                'id' => 456,
                'attr' => 'foo',
                'val' => 'bar',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00',
            ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];

        $source->getConnection()
            ->expects('insert')
            ->with($sql, $bindings)
            ->andThrow(new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception));

        $source->getConnection()
            ->expects('select')
            ->with(
                'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
                [123, 456],
                false,
            )
            ->andReturn([['exists' => 1]]);

        $result = $source->related()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testCreateOrFirstMethodRethrowsAttachViolationWhenExactPivotIsMissing(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $relatedSql = 'insert into "related_table" ("attr", "val", "updated_at", "created_at") values (?, ?, ?, ?)';
        $relatedBindings = ['foo', 'bar', '2023-01-01 00:00:00', '2023-01-01 00:00:00'];

        $source->getConnection()->expects('insert')->with($relatedSql, $relatedBindings)->andThrow(
            new UniqueConstraintViolationException('sqlite', $relatedSql, $relatedBindings, new Exception)
        );
        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            false,
            [],
        )->andReturn([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $pivotSql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $pivotBindings = [456, 123];
        $attachException = new UniqueConstraintViolationException('sqlite', $pivotSql, $pivotBindings, new Exception);

        $source->getConnection()->expects('insert')->with($pivotSql, $pivotBindings)->andThrow($attachException);
        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->andReturn([['exists' => 0]]);

        try {
            $source->related()->createOrFirst(['attr' => 'foo'], ['val' => 'bar']);
            $this->fail('Expected the pivot attach violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($attachException, $exception);
        }
    }

    public function testFirstOrCreateMethodRetrievesExistingRelatedAndAssociatesIt(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()
            ->expects('select')
            ->with(
                'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
                [123, 'foo'],
                true,
                [],
            )
            ->andReturn([]);

        $source->getConnection()
            ->expects('select')
            ->with(
                'select * from "related_table" where ("attr" = ?) limit 1',
                ['foo'],
                true,
                [],
            )
            ->andReturn([[
                'id' => 456,
                'attr' => 'foo',
                'val' => 'bar',
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00',
            ]]);

        $source->getConnection()
            ->expects('insert')
            ->with(
                'insert into "pivot_table" ("related_id", "source_id") values (?, ?)',
                [456, 123],
            )
            ->andReturnTrue();

        $result = $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertEquals([
            // Pivot is not loaded when related model is newly created.
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testFirstOrCreateMethodReturnsExistingRelatedWhenExactPivotExistsAfterAttachCollision(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()->expects('select')->with(
            'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
            [123, 'foo'],
            true,
            [],
        )->andReturn([]);

        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            true,
            [],
        )->andReturn([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];

        $source->getConnection()->expects('insert')->with($sql, $bindings)->andThrow(
            new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception)
        );

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->andReturn([['exists' => 1]]);

        $result = $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);

        $this->assertSame(456, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
    }

    public function testFirstOrCreateMethodRethrowsAttachViolationWhenExactPivotIsMissing(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels([$source, new RelatedModel], 'SQLite');
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()->expects('select')->with(
            'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
            [123, 'foo'],
            true,
            [],
        )->andReturn([]);

        $source->getConnection()->expects('select')->with(
            'select * from "related_table" where ("attr" = ?) limit 1',
            ['foo'],
            true,
            [],
        )->andReturn([[
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00',
        ]]);

        $sql = 'insert into "pivot_table" ("related_id", "source_id") values (?, ?)';
        $bindings = [456, 123];
        $attachException = new UniqueConstraintViolationException('sqlite', $sql, $bindings, new Exception);

        $source->getConnection()->expects('insert')->with($sql, $bindings)->andThrow($attachException);
        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, 456],
            false,
        )->andReturn([['exists' => 0]]);

        try {
            $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
            $this->fail('Expected the pivot attach violation to be rethrown.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame($attachException, $exception);
        }
    }

    public function testFirstOrCreateMethodFallsBackToCreateOrFirst(): void
    {
        $source = new class extends SourceModel {
            protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null): BelongsToMany
            {
                $relation = m::mock(BelongsToMany::class)->makePartial();
                $relation->__construct(...func_get_args());
                $instance = new RelatedModel([
                    'id' => 456,
                    'attr' => 'foo',
                    'val' => 'bar',
                    'created_at' => '2023-01-01T00:00:00.000000Z',
                    'updated_at' => '2023-01-01T00:00:00.000000Z',
                    'pivot' => [
                        'source_id' => 123,
                        'related_id' => 456,
                    ],
                ]);
                $instance->exists = true;
                $instance->wasRecentlyCreated = false;
                $instance->syncOriginal();
                $relation
                    ->expects('createOrFirst')
                    ->with(['attr' => 'foo'], ['val' => 'bar'], [], true)
                    ->andReturn($instance);

                return $relation;
            }
        };
        $source->id = 123;
        $source->exists = true;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()
            ->expects('select')
            ->with(
                'select "related_table".*, "pivot_table"."source_id" as "pivot_source_id", "pivot_table"."related_id" as "pivot_related_id" from "related_table" inner join "pivot_table" on "related_table"."id" = "pivot_table"."related_id" where "pivot_table"."source_id" = ? and ("attr" = ?) limit 1',
                [123, 'foo'],
                true,
                [],
            )
            ->andReturn([]);

        $source->getConnection()
            ->expects('select')
            ->with(
                'select * from "related_table" where ("attr" = ?) limit 1',
                ['foo'],
                true,
                [],
            )
            ->andReturn([]);

        $result = $source->related()->firstOrCreate(['attr' => 'foo'], ['val' => 'bar']);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
            'pivot' => [
                'source_id' => 123,
                'related_id' => 456,
            ],
        ], $result->toArray());
    }

    public function testUpdateOrCreateMethodCreatesNewRelated(): void
    {
        $source = new class extends SourceModel {
            protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null): BelongsToMany
            {
                $relation = m::mock(BelongsToMany::class)->makePartial();
                $relation->__construct(...func_get_args());
                $instance = new RelatedModel([
                    'id' => 456,
                    'attr' => 'foo',
                    'val' => 'bar',
                    'created_at' => '2023-01-01T00:00:00.000000Z',
                    'updated_at' => '2023-01-01T00:00:00.000000Z',
                ]);
                $instance->exists = true;
                $instance->wasRecentlyCreated = true;
                $instance->syncOriginal();
                $relation
                    ->expects('firstOrCreate')
                    ->with(['attr' => 'foo'], ['val' => 'baz'], [], true)
                    ->andReturn($instance);

                return $relation;
            }
        };
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );

        $result = $source->related()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'bar',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testUpdateOrCreateMethodUpdatesExistingRelated(): void
    {
        $source = new class extends SourceModel {
            protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null): BelongsToMany
            {
                $relation = m::mock(BelongsToMany::class)->makePartial();
                $relation->__construct(...func_get_args());
                $instance = new RelatedModel([
                    'id' => 456,
                    'attr' => 'foo',
                    'val' => 'bar',
                    'created_at' => '2023-01-01T00:00:00.000000Z',
                    'updated_at' => '2023-01-01T00:00:00.000000Z',
                ]);
                $instance->exists = true;
                $instance->wasRecentlyCreated = false;
                $instance->syncOriginal();
                $relation
                    ->expects('firstOrCreate')
                    ->with(['attr' => 'foo'], ['val' => 'baz'], [], true)
                    ->andReturn($instance);

                return $relation;
            }
        };
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()
            ->expects('update')
            ->with(
                'update "related_table" set "val" = ?, "updated_at" = ? where "id" = ?',
                ['baz', '2023-01-01 00:00:00', 456],
            )
            ->andReturn(1);

        $result = $source->related()->updateOrCreate(['attr' => 'foo'], ['val' => 'baz']);
        $this->assertEquals([
            'id' => 456,
            'attr' => 'foo',
            'val' => 'baz',
            'created_at' => '2023-01-01T00:00:00.000000Z',
            'updated_at' => '2023-01-01T00:00:00.000000Z',
        ], $result->toArray());
    }

    public function testUpdateOrCreateMethodAcceptsClosureValuesAndCreates(): void
    {
        $source = new class extends SourceModel {
            protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null): BelongsToMany
            {
                $relation = m::mock(BelongsToMany::class)->makePartial();
                $relation->__construct(...func_get_args());
                $instance = new RelatedModel([
                    'id' => 456,
                    'attr' => 'foo',
                    'val' => 'bar',
                    'created_at' => '2023-01-01T00:00:00.000000Z',
                    'updated_at' => '2023-01-01T00:00:00.000000Z',
                ]);
                $instance->exists = true;
                $instance->wasRecentlyCreated = true;
                $instance->syncOriginal();
                $relation
                    ->expects('firstOrCreate')
                    ->withArgs(function ($attributes, $values, $joining, $touch) {
                        return $attributes === ['attr' => 'foo']
                            && $values instanceof Closure
                            && $joining === []
                            && $touch === true;
                    })
                    ->andReturn($instance);

                return $relation;
            }
        };
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );

        $callCount = 0;
        $result = $source->related()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'baz'];
        });

        // Closure is forwarded to firstOrCreate which would resolve it on the create path.
        // Because we mocked firstOrCreate above, the closure was never invoked here.
        $this->assertSame(0, $callCount);
        $this->assertSame('bar', $result->val);
    }

    public function testUpdateOrCreateMethodAcceptsClosureValuesAndUpdates(): void
    {
        $source = new class extends SourceModel {
            protected function newBelongsToMany(Builder $query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName = null): BelongsToMany
            {
                $relation = m::mock(BelongsToMany::class)->makePartial();
                $relation->__construct(...func_get_args());
                $instance = new RelatedModel([
                    'id' => 456,
                    'attr' => 'foo',
                    'val' => 'bar',
                    'created_at' => '2023-01-01T00:00:00.000000Z',
                    'updated_at' => '2023-01-01T00:00:00.000000Z',
                ]);
                $instance->exists = true;
                $instance->wasRecentlyCreated = false;
                $instance->syncOriginal();
                $relation
                    ->expects('firstOrCreate')
                    ->withArgs(function ($attributes, $values, $joining, $touch) {
                        return $attributes === ['attr' => 'foo']
                            && $values instanceof Closure
                            && $joining === []
                            && $touch === true;
                    })
                    ->andReturn($instance);

                return $relation;
            }
        };
        $source->id = 123;
        $this->mockConnectionForModels(
            [$source, new RelatedModel],
            'SQLite',
        );
        $source->getConnection()->shouldReceive('transactionLevel')->andReturn(0);
        $source->getConnection()->shouldReceive('getName')->andReturn('sqlite');

        $source->getConnection()
            ->expects('update')
            ->with(
                'update "related_table" set "val" = ?, "updated_at" = ? where "id" = ?',
                ['baz', '2023-01-01 00:00:00', 456],
            )
            ->andReturn(1);

        $callCount = 0;
        $result = $source->related()->updateOrCreate(['attr' => 'foo'], function () use (&$callCount) {
            ++$callCount;

            return ['val' => 'baz'];
        });

        // On the update path firstOrCreate was mocked away, so the closure
        // is only resolved once for the fill() call.
        $this->assertSame(1, $callCount);
        $this->assertSame('baz', $result->val);
    }

    public function testPivotMembershipCheckIncludesEveryConfiguredPivotConstraint(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $related = new RelatedModel;
        $related->id = 456;
        $this->mockConnectionForModels([$source, $related], 'SQLite');

        $relation = (new InspectableBelongsToMany(
            $related->newQuery(),
            $source,
            'pivot_table',
            'source_id',
            'related_id',
            'id',
            'id',
        ))
            ->wherePivot('status', 'active')
            ->wherePivotIn('kind', ['primary', 'secondary'])
            ->wherePivotNull('expired_at')
            ->wherePivotBetween('score', [10, 20]);

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where ("status" = ? and "kind" in (?, ?) and "expired_at" is null and "score" between ? and ?) and "pivot_table"."source_id" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            ['active', 'primary', 'secondary', 10, 20, 123, 456],
            false,
        )->andReturn([['exists' => 1]]);

        $this->assertTrue($relation->hasAttached($related));
    }

    public function testPivotMembershipCheckIncludesMorphDiscriminator(): void
    {
        $source = new SourceModel;
        $source->id = 123;
        $source->exists = true;
        $related = new RelatedModel;
        $related->id = 456;
        $this->mockConnectionForModels([$source, $related], 'SQLite');

        $relation = new InspectableMorphToMany(
            $related->newQuery(),
            $source,
            'source',
            'pivot_table',
            'source_id',
            'related_id',
            'id',
            'id',
        );

        $source->getConnection()->expects('select')->with(
            'select exists(select * from "pivot_table" where "pivot_table"."source_id" = ? and "source_type" = ? and "pivot_table"."related_id" in (?)) as "exists"',
            [123, SourceModel::class, 456],
            false,
        )->andReturn([['exists' => 1]]);

        $this->assertTrue($relation->hasAttached($related));
    }

    protected function mockConnectionForModels(array $models, string $database, array $lastInsertIds = []): void
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

        foreach ($models as $model) {
            /** @var Model $model */
            $class = get_class($model);
            $class::setConnectionResolver($resolver);
        }

        foreach ($lastInsertIds as $id) {
            $connection->expects('getLastInsertId')->andReturn($id);
        }
    }
}

/**
 * @property int $id
 */
class RelatedModel extends Model
{
    protected ?string $table = 'related_table';

    protected array $guarded = [];
}

/**
 * @property int $id
 */
class SourceModel extends Model
{
    protected ?string $table = 'source_table';

    protected array $guarded = [];

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(
            RelatedModel::class,
            'pivot_table',
            'source_id',
            'related_id',
        );
    }
}

class InspectableBelongsToMany extends BelongsToMany
{
    /**
     * Determine if the related model is attached through the current pivot constraints.
     */
    public function hasAttached(Model $instance): bool
    {
        return $this->hasAttachedPivot($instance);
    }
}

class InspectableMorphToMany extends MorphToMany
{
    /**
     * Determine if the related model is attached through the current morph constraints.
     */
    public function hasAttached(Model $instance): bool
    {
        return $this->hasAttachedPivot($instance);
    }
}
