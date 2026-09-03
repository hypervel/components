<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\Arr;
use Hypervel\Validation\Rules\NotIn as BaseNotIn;
use UnitEnum;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotIn extends ObjectValidationAttribute
{
    protected ?BaseNotIn $rule = null;

    protected array $values;

    /**
     * Create a new not-in validation attribute.
     */
    public function __construct(array|Arrayable|string|UnitEnum|BaseNotIn|ExternalReference ...$values)
    {
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

        if (count($values) === 1 && $values[0] instanceof BaseNotIn) {
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

        return new BaseNotIn(Arr::flatten($values));
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'not_in';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static(new BaseNotIn($parameters));
    }
}
