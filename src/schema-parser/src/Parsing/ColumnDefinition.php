<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Parsing;

use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Generation;
use Hypervel\SchemaParser\Identity;

/**
 * A column whose facts may still change before the schema is frozen.
 *
 * @internal
 */
final class ColumnDefinition
{
    public bool $nullable = true;

    public ?string $default = null;

    public bool $autoIncrement = false;

    public ?Identity $identity = null;

    public ?Generation $generation = null;

    public ?string $onUpdate = null;

    public bool $invisible = false;

    public ?string $collation = null;

    public ?string $comment = null;

    /** Whether a PostgreSQL NOT NULL constraint applies to this table only. */
    public bool $noInheritNotNull = false;

    /** Whether a PostgreSQL NOT NULL constraint has not validated existing rows. */
    public bool $unvalidatedNotNull = false;

    /**
     * Create a column definition.
     */
    public function __construct(
        public readonly string $name,
        public ColumnType $type,
    ) {
    }
}
