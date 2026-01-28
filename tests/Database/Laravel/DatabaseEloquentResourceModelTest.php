<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModel;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModelWithGuessableResource;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModelWithUseResourceAttribute;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceTestJsonResource;
use Hypervel\Tests\TestCase;

class DatabaseEloquentResourceModelTest extends TestCase
{
    public function testItCanTransformToExplicitResource()
    {
        $model = new EloquentResourceTestResourceModel();
        $resource = $model->toResource(EloquentResourceTestJsonResource::class);

        $this->assertInstanceOf(EloquentResourceTestJsonResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }

    public function testItThrowsExceptionWhenResourceCannotBeFound()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModel].');

        $model = new EloquentResourceTestResourceModel();
        $model->toResource();
    }

    public function testItCanGuessResourceWhenNotProvided()
    {
        $model = new EloquentResourceTestResourceModelWithGuessableResource();

        class_alias(EloquentResourceTestJsonResource::class, 'Hypervel\Tests\Database\Laravel\Fixtures\Http\Resources\EloquentResourceTestResourceModelWithGuessableResourceResource');

        $resource = $model->toResource();

        $this->assertInstanceOf(EloquentResourceTestJsonResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }

    public function testItCanGuessResourceWhenNotProvidedWithNonResourceSuffix()
    {
        $model = new EloquentResourceTestResourceModelWithGuessableResource();

        class_alias(EloquentResourceTestJsonResource::class, 'Hypervel\Tests\Database\Laravel\Fixtures\Http\Resources\EloquentResourceTestResourceModelWithGuessableResource');

        $resource = $model->toResource();

        $this->assertInstanceOf(EloquentResourceTestJsonResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }

    public function testItCanGuessResourceName()
    {
        $model = new EloquentResourceTestResourceModel();
        $this->assertEquals([
            'Hypervel\Tests\Database\Laravel\Fixtures\Http\Resources\EloquentResourceTestResourceModelResource',
            'Hypervel\Tests\Database\Laravel\Fixtures\Http\Resources\EloquentResourceTestResourceModel',
        ], $model::guessResourceName());
    }

    public function testItCanTransformToResourceViaUseResourceAttribute()
    {
        $model = new EloquentResourceTestResourceModelWithUseResourceAttribute();

        $resource = $model->toResource();

        $this->assertInstanceOf(EloquentResourceTestJsonResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }
}
