<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\Password as BasePassword;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Password extends ObjectValidationAttribute
{
    /**
     * Create a password validation attribute.
     */
    public function __construct(
        protected int|ExternalReference $min = 12,
        protected bool|ExternalReference $letters = false,
        protected bool|ExternalReference $mixedCase = false,
        protected bool|ExternalReference $numbers = false,
        protected bool|ExternalReference $symbols = false,
        protected bool|ExternalReference $uncompromised = false,
        protected int|ExternalReference $uncompromisedThreshold = 0,
        protected bool|ExternalReference $default = false,
        protected ?BasePassword $rule = null,
    ) {
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        if ($this->rule !== null) {
            return $this->rule;
        }

        $min = $this->resolveInteger($this->min, 'min');
        $letters = $this->resolveBoolean($this->letters, 'letters');
        $mixedCase = $this->resolveBoolean($this->mixedCase, 'mixedCase');
        $numbers = $this->resolveBoolean($this->numbers, 'numbers');
        $symbols = $this->resolveBoolean($this->symbols, 'symbols');
        $uncompromised = $this->resolveBoolean($this->uncompromised, 'uncompromised');
        $uncompromisedThreshold = $this->resolveInteger($this->uncompromisedThreshold, 'uncompromisedThreshold');
        $default = $this->resolveBoolean($this->default, 'default');

        if ($default) {
            return BasePassword::default();
        }

        $rule = BasePassword::min($min);

        if ($letters) {
            $rule->letters();
        }

        if ($mixedCase) {
            $rule->mixedCase();
        }

        if ($numbers) {
            $rule->numbers();
        }

        if ($symbols) {
            $rule->symbols();
        }

        if ($uncompromised) {
            $rule->uncompromised($uncompromisedThreshold);
        }

        return $rule;
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'password';
    }

    /**
     * Reject string-based password rule construction.
     */
    public static function create(string ...$parameters): static
    {
        throw CannotBuildValidationRule::create('Cannot create a password rule from string parameters.');
    }

    /**
     * Resolve an integer parameter.
     */
    protected function resolveInteger(int|ExternalReference $value, string $parameter): int
    {
        $value = $this->normalizePossibleExternalReferenceParameter($value);

        if (! is_int($value)) {
            throw CannotBuildValidationRule::create("Password {$parameter} must resolve to an integer.");
        }

        return $value;
    }

    /**
     * Resolve a boolean parameter.
     */
    protected function resolveBoolean(bool|ExternalReference $value, string $parameter): bool
    {
        $value = $this->normalizePossibleExternalReferenceParameter($value);

        if (! is_bool($value)) {
            throw CannotBuildValidationRule::create("Password {$parameter} must resolve to a boolean.");
        }

        return $value;
    }
}
