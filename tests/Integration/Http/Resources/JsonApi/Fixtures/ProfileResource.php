<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class ProfileResource extends JsonApiResource
{
    protected array $relationships = [
        'user' => UserResource::class,
    ];

    #[Override]
    public function toAttributes(Request $request): array
    {
        return [
            'timezone' => $this->timezone,
            'date_of_birth' => $this->date_of_birth,
        ];
    }
}
