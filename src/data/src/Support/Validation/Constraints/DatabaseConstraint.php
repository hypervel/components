<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\Constraints;

use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;

abstract class DatabaseConstraint
{
    /**
     * Apply the constraint to a database validation rule.
     */
    abstract public function apply(Exists|Unique $rule): void;

    /**
     * Resolve a possible external reference.
     */
    protected function parseExternalReference(mixed $parameter): mixed
    {
        return $parameter instanceof ExternalReference ? $parameter->getValue() : $parameter;
    }
}
