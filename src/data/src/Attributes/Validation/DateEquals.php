<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use DateTimeInterface;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateEquals extends StringValidationAttribute
{
    /**
     * Create a date-equals rule attribute.
     */
    public function __construct(protected string|DateTimeInterface|ExternalReference $date)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'date_equals';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->date];
    }

    // The inherited create() keeps the parsed date or field name as written, which the validator resolves.
}
