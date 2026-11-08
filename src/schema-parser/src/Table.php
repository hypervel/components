<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class Table
{
    /** @var array<array-key, Column> */
    private array $columnsByName;

    private ?Index $primaryIndex;

    /**
     * Create a table definition and index its columns and primary key.
     *
     * @param list<Column> $columns columns in declaration order
     * @param list<Index> $indexes
     * @param list<ForeignKey> $foreignKeys
     * @param list<CheckConstraint> $checks
     * @param list<TableReference> $parents
     * @param list<Issue> $issues
     */
    public function __construct(
        public string $name,
        public array $columns,
        public ?string $schema = null,
        public array $indexes = [],
        public array $foreignKeys = [],
        public array $checks = [],
        public ?string $comment = null,
        public ?string $collation = null,
        public ?string $engine = null,
        public array $parents = [],
        public ?TableReference $partitionOf = null,
        public ?string $partitionBounds = null,
        public array $issues = [],
    ) {
        $this->columnsByName = array_column($columns, null, 'name');
        $this->primaryIndex = array_find($indexes, static fn (Index $index): bool => $index->primary);
    }

    /**
     * Find a column by its exact name.
     */
    public function column(string $name): ?Column
    {
        return $this->columnsByName[$name] ?? null;
    }

    /**
     * Get the primary key definition.
     */
    public function primaryKey(): ?Index
    {
        return $this->primaryIndex;
    }

    /**
     * Determine whether the represented table facts have been fully read.
     */
    public function isComplete(): bool
    {
        return $this->issues === [];
    }
}
