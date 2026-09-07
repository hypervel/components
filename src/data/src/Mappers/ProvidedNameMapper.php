<?php

declare(strict_types=1);

namespace Hypervel\Data\Mappers;

class ProvidedNameMapper implements NameMapper
{
    /**
     * Create a new provided name mapper.
     */
    public function __construct(protected readonly string|int $name)
    {
    }

    /**
     * Map the given property name.
     */
    public function map(int|string $name): string|int
    {
        return $this->name;
    }
}
