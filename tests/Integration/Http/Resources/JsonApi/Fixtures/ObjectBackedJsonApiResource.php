<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;

class ObjectBackedJsonApiResource extends JsonApiResource
{
    /**
     * Get the resource's ID.
     */
    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    /**
     * Get the resource's type.
     */
    public function toType(Request $request): string
    {
        return 'things';
    }

    /**
     * Transform the resource into an array.
     */
    public function toAttributes(Request $request): array
    {
        return ['name' => $this->resource->name];
    }
}
