<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Override;

class PostResource extends JsonApiResource
{
    /**
     * The number of times the "comments" relationship closure has been resolved.
     */
    public static int $commentsResolutionCount = 0;

    protected array $attributes = [
        'title',
        'content',
    ];

    #[Override]
    public function toRelationships(Request $request): array
    {
        return [
            'author' => AuthorResource::class,
            'comments' => function () {
                ++static::$commentsResolutionCount;

                return CommentResource::collection(
                    $this->comments->where('content', '!=', 'private')
                );
            },
        ];
    }
}
