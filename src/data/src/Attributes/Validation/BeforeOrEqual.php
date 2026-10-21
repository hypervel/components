<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use DateTimeInterface;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class BeforeOrEqual extends StringValidationAttribute
{
    /**
     * Create a before-or-equal rule attribute.
     */
    public function __construct(protected string|DateTimeInterface|FieldReference|ExternalReference $date)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'before_or_equal';
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
