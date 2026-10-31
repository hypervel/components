<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Confirmed extends StringValidationAttribute
{
    protected ?FieldReference $field;

    /**
     * Create a confirmed rule attribute.
     */
    public function __construct(string|FieldReference|null $field = null)
    {
        $this->field = $field === null ? null : $this->parseFieldReference($field);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'confirmed';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return $this->field === null ? [] : [$this->field];
    }
}
