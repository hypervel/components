<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotRegex extends StringValidationAttribute
{
    /**
     * Create a new not-regex validation attribute.
     */
    public function __construct(protected string|ExternalReference $pattern)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'not_regex';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->pattern];
    }
}
