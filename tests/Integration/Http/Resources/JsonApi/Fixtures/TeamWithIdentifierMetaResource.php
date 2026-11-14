<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class TeamWithIdentifierMetaResource extends JsonApiResource
{
    protected array $relationships = ['users' => UserWithIdentifierMetaResource::class];

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
        // The cycle regression supplies a guard so a broken traversal fails instead of hanging.
        if ($guard = $request->attributes->get('jsonapi.resolutionGuard')) {
            $guard();
        }

        return [
            'name' => $this->name,
            'role' => $this->whenPivotLoadedAs('membership', 'team_user', fn (): string => $this->membership->role),
        ];
    }

    /**
     * Get metadata for the team identifier.
     */
    #[Override]
    public function toIdentifierMeta(Request $request): array
    {
        return [
            'role' => $this->whenPivotLoadedAs('membership', 'team_user', fn (): string => $this->membership->role),
        ];
    }
}
