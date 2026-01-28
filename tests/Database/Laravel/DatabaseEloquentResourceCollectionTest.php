<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceCollectionTestModel;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModelWithUseResourceAttribute;
use Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceTestResourceModelWithUseResourceCollectionAttribute;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceCollectionTestResource;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceTestJsonResource;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceTestJsonResourceCollection;
use Hypervel\Tests\TestCase;

class DatabaseEloquentResourceCollectionTest extends TestCase
{
    public function testItCanTransformToExplicitResource()
    {
        $collection = new Collection([
            new EloquentResourceCollectionTestModel(),
        ]);

        $resource = $collection->toResourceCollection(EloquentResourceCollectionTestResource::class);

        $this->assertInstanceOf(JsonResource::class, $resource);
    }

    public function testItThrowsExceptionWhenResourceCannotBeFound()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Database\Laravel\Fixtures\Models\EloquentResourceCollectionTestModel].');

        $collection = new Collection([
            new EloquentResourceCollectionTestModel(),
        ]);
        $collection->toResourceCollection();
    }

    public function testItCanGuessResourceWhenNotProvided()
    {
        $collection = new Collection([
            new EloquentResourceCollectionTestModel(),
        ]);

        class_alias(EloquentResourceCollectionTestResource::class, 'Hypervel\Tests\Database\Laravel\Fixtures\Http\Resources\EloquentResourceCollectionTestModelResource');

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(JsonResource::class, $resource);
    }

    public function testItCanTransformToResourceViaUseResourceAttribute()
    {
        $collection = new Collection([
            new EloquentResourceTestResourceModelWithUseResourceCollectionAttribute(),
        ]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(EloquentResourceTestJsonResourceCollection::class, $resource);
    }

    public function testItCanTransformToResourceViaUseResourceCollectionAttribute()
    {
        $collection = new Collection([
            new EloquentResourceTestResourceModelWithUseResourceAttribute(),
        ]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
        $this->assertInstanceOf(EloquentResourceTestJsonResource::class, $resource[0]);
    }
}
