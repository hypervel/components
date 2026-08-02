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

        $resolver = new RelationResolver('comments', fn () => RelationResolverTestResource::collection([$first, $second]));

        $resolved = $resolver->handle(new RelationResolverTestModel);

        $this->assertInstanceOf(EloquentCollection::class, $resolved);
        $this->assertSame([$first, $second], $resolved->all());
        $this->assertSame(RelationResolverTestResource::class, $resolver->resourceClass());
    }

    public function testResolvesClosureReturningSingleResource(): void
    {
        $model = new RelationResolverTestModel(['id' => 1]);

        $resolver = new RelationResolver('author', fn () => new RelationResolverTestResource($model));

        $resolved = $resolver->handle(new RelationResolverTestModel);

        $this->assertSame($model, $resolved);
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
        $this->expectExceptionMessage(sprintf(
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
