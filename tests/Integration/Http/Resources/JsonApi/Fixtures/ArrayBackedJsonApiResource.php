<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;

class ArrayBackedJsonApiResource extends JsonApiResource
{
    public function toId(Request $request): ?string
    {
        return (string) $this->resource['id'];
    }

    public function toType(Request $request): ?string
    {
        return 'things';
    }

    public function toAttributes(Request $request): array
    {
        return ['name' => $this->resource['name']];
    }
}
