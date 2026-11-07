<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

final readonly class CheckConstraint
{
    /**
     * Create a check constraint with its unevaluated SQL expression.
     *
     * @param bool $valid whether the declaration validates existing rows; false for NOT VALID or NOT ENFORCED
     */
    public function __construct(
        public ?string $name,
        public string $expression,
        public bool $valid = true,
        public bool $enforced = true,
        public bool $noInherit = false,
    ) {
    }
}
