<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;

class PostCollectionResourceWithPaginationInformation extends ResourceCollection
{
    public ?string $collects = PostResource::class;

    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }

    public function paginationInformation(Request $request): array
    {
        $paginated = $this->resource->toArray();

        return [
            'current_page' => $paginated['current_page'],
            'per_page' => $paginated['per_page'],
            'total' => $paginated['total'],
            'total_page' => $paginated['last_page'],
        ];
    }
}
