<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Concerns;

use Hyperf\Collection\Collection as HyperfCollection;
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

        $mapped = $collects && ! $resource->first() instanceof $collects
            ? $resource->mapInto($collects)
            : $resource->toBase();

        // TODO: Remove once ResourceCollection is fully ported from Laravel.
        // Temporary bridge during Hyperf decoupling - parent class property
        // is typed as Hyperf\Collection\Collection, but we use Hypervel's.
        $this->collection = new HyperfCollection($mapped->all());

        return $this->isPaginatorResource($resource)
            ? $resource->setCollection($this->collection)
            : $this->collection;
    }
}
