<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

readonly class TypeReference
{
    /**
     * Create a PostgreSQL user type or domain reference without assuming an unspecified schema.
     */
    public function __construct(
        public string $name,
        public ?string $schema = null,
    ) {
    }
}
