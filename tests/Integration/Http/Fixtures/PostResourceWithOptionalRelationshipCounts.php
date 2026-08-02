<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithOptionalRelationshipCounts extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'authors' => $this->whenCounted('authors_count'),
            'favourite_posts' => $this->whenCounted('favouritedPosts'),
            'comments' => $this->whenCounted('comments', function (int $count) {
                return "{$count} comments";
            }, 'None'),
        ];
    }
}
