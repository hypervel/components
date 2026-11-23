<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class UserWithLazyTeamAttributeResource extends JsonApiResource
{
    protected array $relationships = [
        'teams' => TeamWithLazyAttributeResource::class,
    ];

    /**
     * Get the resource type.
     */
    #[Override]
    public function toType(Request $request): string
    {
        return 'users';
    }

    /**
     * Transform the resource attributes.
     */
    #[Override]
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
