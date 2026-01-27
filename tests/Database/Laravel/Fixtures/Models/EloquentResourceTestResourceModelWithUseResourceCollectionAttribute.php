<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Database\Eloquent\Attributes\UseResourceCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceTestJsonResourceCollection;

#[UseResourceCollection(EloquentResourceTestJsonResourceCollection::class)]
class EloquentResourceTestResourceModelWithUseResourceCollectionAttribute extends Model
{
    //
}
