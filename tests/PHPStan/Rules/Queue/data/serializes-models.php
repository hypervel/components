<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue\Data;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Queue\SerializesModels;

class Product extends Model
{
}

class Invoice extends Model
{
}

class JobWithoutModelProperty implements ShouldQueue
{
    public function __construct(public int $productId)
    {
    }
}

class ModelHolderNotQueued
{
    public function __construct(public Product $product)
    {
    }
}

class JobWithModelPropertyWithSerializesModels implements ShouldQueue
{
    use SerializesModels;

    public function __construct(public Product $product)
    {
    }
}

abstract class AbstractJobWithModelProperty implements ShouldQueue
{
    public function __construct(public Product $product)
    {
    }
}

class BaseJobWithSerializesModels implements ShouldQueue
{
    use SerializesModels;
}

class JobInheritingSerializesModels extends BaseJobWithSerializesModels
{
    public function __construct(public Product $product)
    {
    }
}

class JobWithProtectedModelProperty implements ShouldQueue
{
    public function __construct(protected Product $product)
    {
    }
}

class JobWithModelPropertyWithoutSerializesModels implements ShouldQueue
{
    public function __construct(public Product $product)
    {
    }
}

class JobWithNullableModelPropertyWithoutSerializesModels implements ShouldQueue
{
    public Product|null $product = null;
}

class JobWithMultipleModelPropertiesWithoutSerializesModels implements ShouldQueue
{
    public function __construct(public Product $product, public Invoice $invoice)
    {
    }
}

class JobInheritingModelProperty extends AbstractJobWithModelProperty
{
}

class JobInheritingModelPropertyWithSerializesModels extends AbstractJobWithModelProperty
{
    use SerializesModels;
}

// Hypervel: a parent's private model state and an Eloquent collection are serialized too, while a
// job's own __serialize() decides its payload.
abstract class AbstractJobWithPrivateModelProperty implements ShouldQueue
{
    public function __construct(private Product $product)
    {
    }
}

class JobInheritingPrivateModelProperty extends AbstractJobWithPrivateModelProperty
{
}

class JobWithModelCollectionProperty implements ShouldQueue
{
    /** @param EloquentCollection<int, Product> $products */
    public function __construct(public EloquentCollection $products)
    {
    }
}

class JobWithOwnSerialization implements ShouldQueue
{
    public function __construct(public Product $product)
    {
    }

    /** @return array{product: mixed} */
    public function __serialize(): array
    {
        return ['product' => $this->product->getKey()];
    }

    /** @param array{product: mixed} $data */
    public function __unserialize(array $data): void
    {
        $this->product = Product::query()->findOrFail($data['product']);
    }
}
