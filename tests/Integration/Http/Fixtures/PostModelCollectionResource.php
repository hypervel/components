<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;

class PostModelCollectionResource extends ResourceCollection
{
    public ?string $collects = Post::class;

    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }
}
