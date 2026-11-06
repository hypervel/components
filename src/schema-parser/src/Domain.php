<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class Domain
{
    /**
     * Create a domain definition.
     *
     * @param list<CheckConstraint> $checks
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public ?string $schema = null,
        public bool $nullable = true,
        public ?string $default = null,
        public array $checks = [],
    ) {
    }
}
