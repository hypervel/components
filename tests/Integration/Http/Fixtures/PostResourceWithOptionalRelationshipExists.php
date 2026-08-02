<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithOptionalRelationshipExists extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'has_authors' => $this->whenExistsLoaded('authors'),
            'has_favourited_posts' => $this->whenExistsLoaded('favouritedPosts', fn (bool $exists) => $exists ? 'Yes' : 'No', 'No'),
            'comment_exists' => $this->whenExistsLoaded('comments'),
        ];
    }
}
