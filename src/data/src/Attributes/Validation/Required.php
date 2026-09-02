<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\RequiringRule;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\RequiredIf;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Required extends ObjectValidationAttribute implements RequiringRule
{
    /**
     * Create a required rule attribute.
     */
    public function __construct(protected ?RequiredIf $rule = null)
    {
    }

    /**
     * Get the Validator rule.
     */
    public function getRule(ValidationPath $path): object|string
    {
        return $this->rule ?? self::keyword();
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'required';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static;
    }
}
