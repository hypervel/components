<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Hypervel\Data\Support\Validation\ValidationPath;

abstract class ObjectValidationAttribute extends ValidationAttribute
{
    /**
     * Get the Validator rule object.
     */
    abstract public function getRule(ValidationPath $path): object|string;
}
