<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

use Hyperf\Resource\Json\JsonResource as BaseJsonResource;
use Hypervel\Router\Contracts\UrlRoutable;

use function Hyperf\Tappable\tap;

class JsonResource extends BaseJsonResource implements UrlRoutable
{
    /**
     * Create new anonymous resource collection.
     */
    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        return tap(new AnonymousResourceCollection($resource, static::class), function ($collection) {
            $collection->preserveKeys = (new static([]))->preserveKeys;
        });
    }
}
