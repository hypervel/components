<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use BackedEnum;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class WhereInConstraint extends DatabaseConstraint
{
    /**
     * Create a new where-in constraint.
     */
    public function __construct(
        public readonly string|ExternalReference $column,
        public readonly array|Arrayable|BackedEnum|ExternalReference $values,
    ) {
    }

    /**
     * Apply the constraint to a database validation rule.
     */
    public function apply(Exists|Unique $rule): void
    {
        $rule->whereIn(
            $this->parseExternalReference($this->column),
            $this->parseExternalReference($this->values),
        );
    }
}
