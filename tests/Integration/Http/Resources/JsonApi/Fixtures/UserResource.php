<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class UserResource extends JsonApiResource
{
    protected array $relationships = [
        'comments',
        'profile',
        'posts',
        'teams',
        'chaperonePosts' => PostResource::class,
    ];

    #[Override]
    public function toAttributes(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
