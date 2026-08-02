<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;

class PostCollectionResource extends ResourceCollection
{
    public ?string $collects = PostResource::class;

    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }
}
