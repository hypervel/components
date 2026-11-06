<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class View
{
    /**
     * Create a view definition without inferring its query result types.
     */
    public function __construct(
        public string $name,
        public string $definition,
        public ?string $schema = null,
        public bool $materialized = false,
    ) {
    }
}
