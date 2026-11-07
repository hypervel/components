<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

/**
 * Preserve declared type identity separately from a domain's resolved base.
 * PostgreSQL standard aliases use canonical names, while quoted user types
 * retain their case and qualification.
 */
final readonly class ColumnType
{
    /**
     * Create a column type definition.
     *
     * @param list<string> $modifiers Type modifiers, e.g. ['10', '2'] for numeric(10,2).
     * @param int $dimensions array dimensions; 0 for a scalar column
     * @param list<string> $values decoded enum or set labels in declaration order
     * @param null|string $intervalFields lower-case PostgreSQL interval fields, e.g. 'day to second'
     */
    public function __construct(
        public string $name,
        public ?string $schema = null,
        public array $modifiers = [],
        public int $dimensions = 0,
        public bool $unsigned = false,
        public array $values = [],
        public ?self $base = null,
        public ?string $intervalFields = null,
    ) {
    }

    /**
     * Determine whether the declared type is an array.
     */
    public function isArray(): bool
    {
        return $this->dimensions > 0;
    }
}
