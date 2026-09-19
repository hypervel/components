<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletingScope;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseSoftDeletingScopeTest extends TestCase
{
    public function testApplyingScopeToABuilder(): void
    {
        $scope = m::mock(SoftDeletingScope::class . '[extend]');
        $builder = m::mock(EloquentBuilder::class);
        $model = m::mock(Model::class);
        $model->expects('getQualifiedDeletedAtColumn')->andReturn('table.deleted_at');
        $builder->expects('whereNull')->with('table.deleted_at');

        $scope->apply($builder, $model);
    }

    public function testRestoreExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));
        $scope = new SoftDeletingScope;
        $scope->extend($builder);
        $callback = $builder->getMacro('restore');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withTrashed');
        $model = m::mock(Model::class);
        $givenBuilder->expects('getModel')->andReturn($model);
        $model->expects('getDeletedAtColumn')->andReturn('deleted_at');
        $givenBuilder->expects('update')->with(['deleted_at' => null]);

        $callback($givenBuilder);
    }

    public function testRestoreOrCreateExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));

        $scope = new SoftDeletingScope;
        $scope->extend($builder);
        $callback = $builder->getMacro('restoreOrCreate');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withTrashed');
        $attributes = ['name' => 'foo'];
        $values = ['email' => 'bar'];
        $model = m::mock(Model::class);
        $givenBuilder->expects('firstOrCreate')->with($attributes, $values)->andReturn($model);
        $model->expects('restore')->andReturn(true);
        $result = $callback($givenBuilder, $attributes, $values);

        $this->assertEquals($model, $result);
    }

    public function testCreateOrRestoreExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));

        $scope = new SoftDeletingScope;
        $scope->extend($builder);
        $callback = $builder->getMacro('createOrRestore');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withTrashed');
        $attributes = ['name' => 'foo'];
        $values = ['email' => 'bar'];
        $model = m::mock(Model::class);
        $givenBuilder->expects('createOrFirst')->with($attributes, $values)->andReturn($model);
        $model->expects('restore')->andReturn(true);
        $result = $callback($givenBuilder, $attributes, $values);

        $this->assertEquals($model, $result);
    }

    public function testWithTrashedExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));
        $scope = m::mock(SoftDeletingScope::class . '[remove]');
        $scope->extend($builder);
        $callback = $builder->getMacro('withTrashed');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('withoutGlobalScope')->with($scope)->andReturn($givenBuilder);
        $result = $callback($givenBuilder);

        $this->assertEquals($givenBuilder, $result);
    }

    public function testOnlyTrashedExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));
        $model = m::mock(Model::class)->makePartial();
        $scope = m::mock(SoftDeletingScope::class . '[remove]');
        $scope->extend($builder);
        $callback = $builder->getMacro('onlyTrashed');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('getModel')->andReturn($model);
        $givenBuilder->expects('withoutGlobalScope')->with($scope)->andReturn($givenBuilder);
        $model->expects('getQualifiedDeletedAtColumn')->andReturn('table.deleted_at');
        $givenBuilder->expects('whereNotNull')->with('table.deleted_at');
        $result = $callback($givenBuilder);

        $this->assertEquals($givenBuilder, $result);
    }

    public function testWithoutTrashedExtension(): void
    {
        $builder = new EloquentBuilder(new BaseBuilder(
            m::mock(ConnectionInterface::class),
            m::mock(Grammar::class),
            m::mock(Processor::class)
        ));
        $model = m::mock(Model::class)->makePartial();
        $scope = m::mock(SoftDeletingScope::class . '[remove]');
        $scope->extend($builder);
        $callback = $builder->getMacro('withoutTrashed');
        $givenBuilder = m::mock(EloquentBuilder::class);
        $givenBuilder->expects('getModel')->andReturn($model);
        $givenBuilder->expects('withoutGlobalScope')->with($scope)->andReturn($givenBuilder);
        $model->expects('getQualifiedDeletedAtColumn')->andReturn('table.deleted_at');
        $givenBuilder->expects('whereNull')->with('table.deleted_at');
        $result = $callback($givenBuilder);

        $this->assertEquals($givenBuilder, $result);
    }
}
