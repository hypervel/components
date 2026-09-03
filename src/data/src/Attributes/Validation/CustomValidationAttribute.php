<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Data\Support\Validation\ValidationRule;

abstract class CustomValidationAttribute extends ValidationRule
{
    /**
     * Get the Validator rules.
     *
     * @return array<object|string>|object|string
     */
    abstract public function getRules(ValidationPath $path): array|object|string;
}
