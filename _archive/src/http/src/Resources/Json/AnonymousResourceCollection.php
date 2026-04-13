<?php

declare(strict_types=1);

namespace Hypervel\Http\Resources\Json;

class AnonymousResourceCollection extends ResourceCollection
{
    /**
     * The name of the resource being collected.
     */
    public ?string $collects = null;

    /**
     * Indicates if the collection keys should be preserved.
     */
    public bool $preserveKeys = false;

    /**
     * Create a new anonymous resource collection.
     */
    public function __construct(mixed $resource, string $collects)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /**
     * Indicate that the collection keys should be preserved.
     */
    public function preserveKeys(bool $value = true): static
    {
        $this->preserveKeys = $value;

        return $this;
    }
}
