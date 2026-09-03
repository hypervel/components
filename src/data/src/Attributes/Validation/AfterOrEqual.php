<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use DateTimeInterface;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class AfterOrEqual extends StringValidationAttribute
{
    /**
     * Create an after-or-equal rule attribute.
     */
    public function __construct(protected string|DateTimeInterface|ExternalReference|FieldReference $date)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'after_or_equal';
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
