<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\References\FieldReference;
use Hypervel\Data\Support\Validation\RuleDenormalizer;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Data\Support\Validation\ValidationRule;
use Hypervel\Support\CarbonImmutable;
use Stringable;

abstract class ValidationAttribute extends ValidationRule implements Stringable
{
    /**
     * Get the Validator rule keyword.
     */
    abstract public static function keyword(): string;

    /**
     * Create the attribute from parsed string parameters.
     */
    abstract public static function create(string ...$parameters): static;

    /**
     * Get the rule in Validator string form.
     */
    public function __toString(): string
    {
        return implode('|', (new RuleDenormalizer)->execute($this, ValidationPath::create()));
    }

    /**
     * Parse a validation date value.
     */
    protected static function parseDateValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($value === 'tomorrow') {
            return $value;
        }

        $time = strtotime($value);

        if ($time === false) {
            return $value;
        }

        return CarbonImmutable::parse($time);
    }

    /**
     * Parse a validation boolean value.
     */
    protected static function parseBooleanValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($value === 'true' || $value === '1') {
            return 'true';
        }

        if ($value === 'false' || $value === '0') {
            return 'false';
        }

        return $value;
    }

    /**
     * Parse a field reference.
     */
    protected function parseFieldReference(
        string|FieldReference $reference
    ): FieldReference {
        return $reference instanceof FieldReference
            ? $reference
            : new FieldReference($reference);
    }

    /**
     * Resolve a possible external reference parameter.
     */
    protected function normalizePossibleExternalReferenceParameter(mixed $parameter): mixed
    {
        return $parameter instanceof ExternalReference ? $parameter->getValue() : $parameter;
    }
}
