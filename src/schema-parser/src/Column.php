<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class Column
{
    /**
     * Create a column definition.
     *
     * The default is SQL text, including explicit NULL expressions. A null
     * property means no default expression was supplied or inherited.
     *
     * @param bool $nullable whether the column lacks a validated not-null guarantee; a domain restriction alone is insufficient
     */
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $nullable = true,
        public ?string $default = null,
        public bool $autoIncrement = false,
        public ?Identity $identity = null,
        public ?Generation $generation = null,
        public ?string $onUpdate = null,
        public bool $invisible = false,
        public ?string $collation = null,
        public ?string $comment = null,
    ) {
    }
}
