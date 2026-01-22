<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

/**
 * Anonymous resource collection for wrapping arbitrary collections.
 *
 * This class extends ResourceCollection to ensure proper type hierarchy
 * within Hypervel's resource system.
 */
class AnonymousResourceCollection extends ResourceCollection
{
    /**
     * Create a new anonymous resource collection.
     *
     * @param mixed $resource the resource being collected
     * @param string $collects the name of the resource being collected
     */
    public function __construct(mixed $resource, string $collects)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }
}
