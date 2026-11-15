<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use BackedEnum;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Support\Arr;
use Hypervel\Validation\Rules\DoesntContain as BaseDoesntContain;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DoesntContain extends ObjectValidationAttribute
{
    protected array $values;

    /**
     * Create a doesn't-contain rule attribute.
     */
    public function __construct(array|int|string|float|bool|BackedEnum|BaseDoesntContain|ExternalReference|null ...$values)
    {
        $this->values = Arr::flatten($values);
    }

    /**
     * Get the Validator rule object without stringifying its values.
     */
    public function getRule(ValidationPath $path): object|string
    {
        $values = Arr::flatten(array_map(
            fn (mixed $value): mixed => $this->normalizePossibleExternalReferenceParameter($value),
            Arr::flatten($this->parameters()),
        ));

        return count($values) === 1 && $values[0] instanceof BaseDoesntContain
            ? $values[0]
            : new BaseDoesntContain($values);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'doesnt_contain';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->values];
    }

    /**
     * Create the attribute from normalized membership parameters.
     */
    public static function create(?string ...$parameters): static
    {
        return new static(...$parameters);
    }
}
