<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Generator;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Support\LazyCollection;
use Override;

class TeamWithLazyAttributeResource extends JsonApiResource
{
    /**
     * Get the resource type.
     */
    #[Override]
    public function toType(Request $request): string
    {
        return 'teams';
    }

    /**
     * Transform the resource attributes.
     */
    #[Override]
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'tags' => LazyCollection::make(fn (): Generator => yield 'php'),
        ];
    }
}
