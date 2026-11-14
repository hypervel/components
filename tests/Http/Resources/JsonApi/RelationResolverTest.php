<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resources\JsonApi;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Http\Resources\JsonApi\RelationResolver;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class RelationResolverTest extends TestCase
{
    public function testResolvesClosureReturningResourceCollection(): void
    {
        $first = new RelationResolverTestModel(['id' => 1]);
        $second = new RelationResolverTestModel(['id' => 2]);

        $resources = RelationResolverTestResource::collection([$first, $second]);
        $resolver = new RelationResolver('comments', fn () => $resources);

        $resolved = $resolver->handle(new RelationResolverTestModel);

        $this->assertSame($resources->collection, $resolved);
        $this->assertSame(RelationResolverTestResource::class, $resolver->resourceClass());
    }

    public function testResolvesClosureReturningSingleResource(): void
    {
        $model = new RelationResolverTestModel(['id' => 1]);
        $resource = new RelationResolverTestResource($model);

        $resolver = new RelationResolver('author', fn () => $resource);

        $resolved = $resolver->handle(new RelationResolverTestModel);

        $this->assertSame($resource, $resolved);
        $this->assertSame(RelationResolverTestResource::class, $resolver->resourceClass());
    }

    public function testResolvesClosureReturningRawModels(): void
    {
        $model = new RelationResolverTestModel(['id' => 1]);

        $resolver = new RelationResolver('comments', fn () => new EloquentCollection([$model]));

        $resolved = $resolver->handle(new RelationResolverTestModel);

        $this->assertInstanceOf(EloquentCollection::class, $resolved);
        $this->assertSame([$model], $resolved->all());
        $this->assertNull($resolver->resourceClass());
    }

    public function testResolvesClosureReturningNull(): void
    {
        $resolver = new RelationResolver('author', fn () => null);

        $this->assertNull($resolver->handle(new RelationResolverTestModel));
        $this->assertNull($resolver->resourceClass());
    }

    public function testRejectsMissingStringResourceClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf(
            'Resource class [%s] for relationship [author] does not exist.',
            MissingRelationResolverTestResource::class,
        ));

        new RelationResolver('author', MissingRelationResolverTestResource::class);
    }
}

class RelationResolverTestModel extends Model
{
    protected array $guarded = [];
}

class RelationResolverTestResource extends JsonApiResource
{
}
