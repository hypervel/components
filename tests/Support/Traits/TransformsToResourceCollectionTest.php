<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Traits;

use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Attributes\UseResourceCollection;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Concerns\TransformsToResource;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use LogicException;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class TransformsToResourceCollectionTest extends TestCase
{
    public function testToResourceCollectionWithExplicitClass(): void
    {
        $model = new ResourceCollectionTestModel();
        $collection = new EloquentCollection([$model]);

        $resource = $collection->toResourceCollection(ResourceCollectionTestResource::class);

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
    }

    public function testToResourceCollectionReturnsEmptyCollectionForEmptyInput(): void
    {
        $collection = new EloquentCollection([]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(ResourceCollection::class, $resource);
    }

    public function testToResourceCollectionThrowsExceptionWhenResourceCannotBeFound(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Support\Traits\ResourceCollectionTestModel].');

        $model = new ResourceCollectionTestModel();
        $collection = new EloquentCollection([$model]);

        $collection->toResourceCollection();
    }

    public function testToResourceCollectionUsesUseResourceCollectionAttribute(): void
    {
        $model = new ResourceCollectionTestModelWithCollectionAttribute();
        $collection = new EloquentCollection([$model]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(ResourceCollectionTestResourceCollection::class, $resource);
    }

    public function testToResourceCollectionUsesUseResourceAttributeWithCollection(): void
    {
        $model = new ResourceCollectionTestModelWithResourceAttribute();
        $collection = new EloquentCollection([$model]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
        $this->assertInstanceOf(ResourceCollectionTestResource::class, $resource[0]);
    }

    public function testToResourceCollectionPrefersUseResourceCollectionOverUseResource(): void
    {
        $model = new ResourceCollectionTestModelWithBothAttributes();
        $collection = new EloquentCollection([$model]);

        $resource = $collection->toResourceCollection();

        // UseResourceCollection should take precedence
        $this->assertInstanceOf(ResourceCollectionTestResourceCollection::class, $resource);
    }

    public function testSupportCollectionHasToResourceCollectionMethod(): void
    {
        $model = new ResourceCollectionTestModelWithResourceAttribute();
        $collection = new Collection([$model]);

        $resource = $collection->toResourceCollection();

        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
    }

    public function testToResourceCollectionThrowsForNonObjectItems(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource collection guesser expects the collection to contain objects.');

        $collection = new Collection(['string', 'items']);

        $collection->toResourceCollection();
    }

    public function testToResourceCollectionThrowsForItemsWithoutGuessResourceName(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Expected class stdClass to implement guessResourceName method.');

        $collection = new Collection([new stdClass()]);

        $collection->toResourceCollection();
    }

    public function testExplicitResourceTakesPrecedenceOverAttribute(): void
    {
        $model = new ResourceCollectionTestModelWithResourceAttribute();
        $collection = new EloquentCollection([$model]);

        $resource = $collection->toResourceCollection(ResourceCollectionTestAlternativeResource::class);

        // Explicit class should be used, not the attribute
        $this->assertInstanceOf(AnonymousResourceCollection::class, $resource);
    }
}

// Test fixtures

class ResourceCollectionTestModel extends Model
{
    use TransformsToResource;

    protected ?string $table = 'test_models';
}

#[UseResourceCollection(ResourceCollectionTestResourceCollection::class)]
class ResourceCollectionTestModelWithCollectionAttribute extends Model
{
    use TransformsToResource;

    protected ?string $table = 'test_models';
}

#[UseResource(ResourceCollectionTestResource::class)]
class ResourceCollectionTestModelWithResourceAttribute extends Model
{
    use TransformsToResource;

    protected ?string $table = 'test_models';
}

#[UseResource(ResourceCollectionTestResource::class)]
#[UseResourceCollection(ResourceCollectionTestResourceCollection::class)]
class ResourceCollectionTestModelWithBothAttributes extends Model
{
    use TransformsToResource;

    protected ?string $table = 'test_models';
}

class ResourceCollectionTestResource extends JsonResource
{
}

class ResourceCollectionTestAlternativeResource extends JsonResource
{
}

class ResourceCollectionTestResourceCollection extends ResourceCollection
{
}
