<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\FieldReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ExcludeWithout extends StringValidationAttribute
{
    protected FieldReference $field;

    /** @var array<array-key, FieldReference> */
    protected array $fields;

    /**
     * Create a new exclude-without validation attribute.
     */
    public function __construct(
        string|FieldReference $field,
        string|FieldReference ...$fields,
    ) {
        $this->field = $this->parseFieldReference($field);
        $this->fields = array_map($this->parseFieldReference(...), $fields);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'exclude_without';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [
            $this->field,
            $this->fields,
        ];
    }
}
