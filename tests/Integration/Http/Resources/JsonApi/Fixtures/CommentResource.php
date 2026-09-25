<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Resources\JsonApi\JsonApiResource;

class CommentResource extends JsonApiResource
{
    /**
     * The resource's attributes.
     */
    public array $attributes = [
        'content',
    ];

    /**
     * The resource's relationships.
     */
    public array $relationships = [
        'posts',
        'commenter' => UserResource::class,
    ];
}
