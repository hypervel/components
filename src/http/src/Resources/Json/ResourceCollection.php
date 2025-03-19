<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

use Hyperf\Resource\Json\ResourceCollection as BaseResourceCollection;
use Hypervel\Http\Resources\Concerns\CollectsResources;

class ResourceCollection extends BaseResourceCollection
{
    use CollectsResources;
}
