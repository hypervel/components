<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class TableReference
{
    /**
     * Create a table reference without assuming an unspecified schema.
     */
    public function __construct(
        public string $name,
        public ?string $schema = null,
    ) {
    }
}
