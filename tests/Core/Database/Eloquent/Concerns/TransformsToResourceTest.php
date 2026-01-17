<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Core\Database\Eloquent\Models\TransformsToResourceTestModelInModelsNamespace;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class TransformsToResourceTest extends TestCase
{
    public function testToResourceWithExplicitClass(): void
    {
        $model = new TransformsToResourceTestModel();
        $resource = $model->toResource(TransformsToResourceTestResource::class);

        $this->assertInstanceOf(TransformsToResourceTestResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }

    public function testToResourceThrowsExceptionWhenResourceCannotBeFound(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Core\Database\Eloquent\Concerns\TransformsToResourceTestModel].');

        $model = new TransformsToResourceTestModel();
        $model->toResource();
    }

    public function testToResourceUsesUseResourceAttribute(): void
    {
        $model = new TransformsToResourceTestModelWithAttribute();
        $resource = $model->toResource();

        $this->assertInstanceOf(TransformsToResourceTestResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }

    public function testGuessResourceNameReturnsEmptyArrayForNonModelsNamespace(): void
    {
        // Model not in a \Models\ namespace
        $result = TransformsToResourceTestModel::guessResourceName();

        $this->assertSame([], $result);
    }

    public function testGuessResourceNameReturnsCorrectNamesForModelsNamespace(): void
    {
        // This model is in a \Models\ namespace
        $result = TransformsToResourceTestModelInModelsNamespace::guessResourceName();

        $this->assertSame([
            'Hypervel\Tests\Core\Database\Eloquent\Http\Resources\TransformsToResourceTestModelInModelsNamespaceResource',
            'Hypervel\Tests\Core\Database\Eloquent\Http\Resources\TransformsToResourceTestModelInModelsNamespace',
        ], $result);
    }

    public function testExplicitResourceTakesPrecedenceOverAttribute(): void
    {
        $model = new TransformsToResourceTestModelWithAttribute();
        $resource = $model->toResource(TransformsToResourceTestAlternativeResource::class);

        // Explicit class should be used, not the attribute
        $this->assertInstanceOf(TransformsToResourceTestAlternativeResource::class, $resource);
        $this->assertSame($model, $resource->resource);
    }
}

// Test fixtures

class TransformsToResourceTestModel extends Model
{
    protected ?string $table = 'test_models';
}

#[UseResource(TransformsToResourceTestResource::class)]
class TransformsToResourceTestModelWithAttribute extends Model
{
    protected ?string $table = 'test_models';
}

class TransformsToResourceTestResource extends JsonResource
{
}

class TransformsToResourceTestAlternativeResource extends JsonResource
{
}
