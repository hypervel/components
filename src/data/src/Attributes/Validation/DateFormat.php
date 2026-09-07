<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateFormat extends StringValidationAttribute
{
    protected array $format;

    /**
     * Create a date-format rule attribute.
     */
    public function __construct(string|array|ExternalReference ...$format)
    {
        $this->format = Arr::flatten($format);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'date_format';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->format];
    }
}
