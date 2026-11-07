<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

use Hypervel\SchemaParser\CheckConstraint;
use Hypervel\SchemaParser\ForeignKey;
use Hypervel\SchemaParser\Index;
use Hypervel\SchemaParser\Issue;
use Hypervel\SchemaParser\TableReference;

/**
 * A table whose facts may still change before the schema is frozen.
 *
 * @internal
 */
final class TableDefinition
{
    /** @var array<array-key, ColumnDefinition> columns by name, in declaration order */
    public array $columns = [];

    /** @var list<Index> */
    public array $indexes = [];

    /** @var list<ForeignKey> */
    public array $foreignKeys = [];

    /** @var array<int, true> positions of PostgreSQL foreign keys that belong to a foreign key of the parent partitioned table */
    public array $attachedForeignKeys = [];

    /** @var array<int, list<string>> issue messages of the foreign keys, by position, that are only partly represented */
    public array $foreignKeyIssues = [];

    /** Whether the table has PostgreSQL indexes that are not represented, which its partitions also get */
    public bool $unrepresentedIndexes = false;

    /** Whether a parent foreign key may be missing from the table's PostgreSQL foreign keys, as from its partitions' */
    public bool $unresolvedForeignKeys = false;

    /** @var list<CheckConstraint> */
    public array $checks = [];

    public ?string $comment = null;

    public ?string $collation = null;

    public ?string $engine = null;

    /** @var list<TableReference> */
    public array $parents = [];

    public ?TableReference $partitionOf = null;

    public ?string $partitionBounds = null;

    /** @var list<Issue> */
    public array $issues = [];

    /** @var array<array-key, array{valid: bool, noInherit: bool}> PostgreSQL NOT NULL table constraints by column, applied once inherited columns merge */
    public array $notNullConstraints = [];

    /**
     * Create a table definition.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $schema = null,
    ) {
    }
}
