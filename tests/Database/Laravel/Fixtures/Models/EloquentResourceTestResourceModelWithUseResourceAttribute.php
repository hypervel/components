<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Models;

use Hypervel\Database\Eloquent\Attributes\UseResource;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Database\Laravel\Fixtures\Resources\EloquentResourceTestJsonResource;

#[UseResource(EloquentResourceTestJsonResource::class)]
class EloquentResourceTestResourceModelWithUseResourceAttribute extends Model
{
}
