<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Fixtures;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

class PostResourceWithAnonymousResourceCollectionWithPaginationInformation extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'custom' => true];
    }

    /**
     * Create a new anonymous resource collection.
     */
    public static function collection(mixed $resource): AnonymousResourceCollectionWithPaginationInformation
    {
        return tap(new AnonymousResourceCollectionWithPaginationInformation($resource, static::class), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }
}
