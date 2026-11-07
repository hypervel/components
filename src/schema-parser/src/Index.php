<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class Index
{
    /**
     * Create an index definition.
     *
     * @param list<IndexColumn> $columns
     * @param list<string> $include payload columns, not part of the indexed key
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public array $include = [],
        public ?string $type = null,
        public bool $unique = false,
        public bool $primary = false,
        public bool $constraint = false,
        public bool $nullsNotDistinct = false,
        public ?Deferral $deferral = null,
        public ?string $where = null,
    ) {
    }
}
