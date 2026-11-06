<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class IndexColumn
{
    /**
     * Create an index key from a column name or SQL expression.
     */
    public function __construct(
        public ?string $name,
        public ?string $expression = null,
        public bool $descending = false,
        public ?bool $nullsFirst = null,
        public ?int $length = null,
        public ?string $collation = null,
        public ?string $operatorClass = null,
    ) {
    }
}
