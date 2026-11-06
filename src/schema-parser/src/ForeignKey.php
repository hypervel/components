<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class ForeignKey
{
    /**
     * Create a foreign key definition.
     *
     * @param list<string> $columns
     * @param list<string> $foreignColumns
     */
    public function __construct(
        public ?string $name,
        public array $columns,
        public TableReference $foreignTable,
        public array $foreignColumns = [],
        public ReferentialAction $onUpdate = ReferentialAction::NoAction,
        public ReferentialAction $onDelete = ReferentialAction::NoAction,
        public ForeignKeyMatch $match = ForeignKeyMatch::Simple,
        public ?Deferral $deferral = null,
        public bool $valid = true,
        public bool $enforced = true,
    ) {
    }
}
