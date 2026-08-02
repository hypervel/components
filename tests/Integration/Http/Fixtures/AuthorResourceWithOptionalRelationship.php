<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class AuthorResourceWithOptionalRelationship extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'posts_count' => $this->whenLoaded('posts', function () {
                return $this->posts->count() . ' posts';
            }, function () {
                return 'not loaded';
            }),
            'latest_post_title' => $this->whenLoaded('posts', function () {
                return $this->posts->first()?->title ?: 'no posts yet';
            }, 'not loaded'),
        ];
    }
}
