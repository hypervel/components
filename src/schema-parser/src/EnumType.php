<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

/**
 * A type declared with `CREATE TYPE ... AS ENUM`.
 *
 * PostgreSQL enums have an identity independent of the columns using them.
 */
final readonly class EnumType
{
    /**
     * Create an enum type definition.
     *
     * @param list<string> $values labels, in declaration order
     */
    public function __construct(
        public string $name,
        public array $values,
        public ?string $schema = null,
    ) {
    }
}
