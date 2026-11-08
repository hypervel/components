<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

readonly class Generation
{
    /**
     * Create a generated column definition.
     */
    public function __construct(
        public GenerationType $type,
        public string $expression,
    ) {
    }
}
