<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;

class PostResourceWithOptionalRelationshipUsingNamedParameters extends PostResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => new AuthorResource($this->whenLoaded('author')),
            'author_defaulting_to_null' => new AuthorResource($this->whenLoaded('author', default: null)),
            'author_name' => $this->whenLoaded('author', fn (Author $author) => $author->name, 'Anonymous'),
        ];
    }
}
