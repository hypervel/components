<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithOptionalRelationship extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comments' => new CommentCollection($this->whenLoaded('comments')),
            'author' => new AuthorResource($this->whenLoaded('author')),
            'author_name' => $this->whenLoaded('author', function () {
                return $this->author->name;
            }),
        ];
    }
}
