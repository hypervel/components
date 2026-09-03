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

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return parent::create(
            self::parseDateValue($parameters[0]),
        );
    }
}
