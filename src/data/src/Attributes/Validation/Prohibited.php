<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\ProhibitedIf;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Prohibited extends ObjectValidationAttribute
{
    /**
     * Create a prohibited validation attribute.
     */
    public function __construct(protected ?ProhibitedIf $rule = null)
    {
    }

    /**
     * Get the Validator rule object or keyword.
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
        return 'prohibited';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static;
    }
}
