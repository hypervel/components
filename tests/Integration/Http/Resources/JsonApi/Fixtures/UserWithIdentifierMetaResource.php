<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class UserWithIdentifierMetaResource extends JsonApiResource
{
    protected array $relationships = [
        'teams' => TeamWithIdentifierMetaResource::class,
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
            'role' => $this->whenPivotLoadedAs('membership', 'team_user', fn (): string => $this->membership->role),
        ];
    }

    /**
     * Get metadata for the user identifier.
     */
    #[Override]
    public function toIdentifierMeta(Request $request): array
    {
        return [
            $this->mergeWhen($this->hasPivotLoadedAs('membership', 'team_user'), fn (): array => ['role' => $this->membership->role]),
        ];
    }
}
