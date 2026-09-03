<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use Closure;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

class WhereConstraint extends DatabaseConstraint
{
    /**
     * Create a new where constraint.
     */
    public function __construct(
        public readonly Closure|string|ExternalReference $column,
        public readonly mixed $value = null,
    ) {
    }

    /**
     * Apply the constraint to a database validation rule.
     */
    public function apply(Exists|Unique $rule): void
    {
        $rule->where(
            $this->parseExternalReference($this->column),
            $this->parseExternalReference($this->value),
        );
    }
}
