<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Concerns;

use Hyperf\Resource\Value\MissingValue;
use Hypervel\Support\Collection;

trait CollectsResources
{
    /**
     * Map the given collection resource into its individual resources.
     */
    protected function collectResource(mixed $resource): mixed
    {
        if ($resource instanceof MissingValue) {
            return $resource;
        }

        if (is_array($resource)) {
            $resource = new Collection($resource);
        }

        $collects = $this->collects();

        $this->collection = $collects && ! $resource->first() instanceof $collects
            ? $resource->mapInto($collects)
            : $resource->toBase();

        return $this->isPaginatorResource($resource)
            ? $resource->setCollection($this->collection)
            : $this->collection;
    }
}
