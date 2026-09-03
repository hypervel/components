<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\Arr;
use Hypervel\Validation\Rules\In as BaseIn;
use UnitEnum;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class In extends ObjectValidationAttribute
{
    protected ?BaseIn $rule = null;

    private array $values;

    /**
     * Create a new in validation attribute.
     */
    public function __construct(
        array|Arrayable|string|UnitEnum|BaseIn|ExternalReference ...$values,
    ) {
        $this->values = $values;
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        if ($this->rule !== null) {
            return $this->rule;
        }

        $values = array_map(
            fn (mixed $value) => $this->normalizePossibleExternalReferenceParameter($value),
            $this->values,
        );

        if (count($values) === 1 && $values[0] instanceof BaseIn) {
            return $this->rule = $values[0];
        }

        $values = array_map(
            fn (mixed $value) => $value instanceof Arrayable ? $value->toArray() : $value,
            $values,
        );
        $values = Arr::flatten($values);

        $values = array_map(function (mixed $value) {
            $value = $this->normalizePossibleExternalReferenceParameter($value);

            return $value instanceof Arrayable ? $value->toArray() : $value;
        }, $values);

        return new BaseIn(Arr::flatten($values));
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'in';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static(new BaseIn($parameters));
    }
}
