<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\Enum as EnumRule;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Enum extends ObjectValidationAttribute
{
    /**
     * Create a new enum validation attribute.
     */
    public function __construct(
        protected string|EnumRule|ExternalReference $enum,
        protected ?EnumRule $rule = null,
        protected ?array $only = null,
        protected ?array $except = null,
    ) {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'enum';
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        if ($this->rule !== null) {
            return $this->rule;
        }

        $enum = $this->normalizePossibleExternalReferenceParameter($this->enum);

        $rule = match (true) {
            $enum instanceof EnumRule => $enum,
            is_string($enum) => new EnumRule($enum),
            default => throw CannotBuildValidationRule::create(sprintf(
                'Enum validation rule requires an enum class or Enum rule; [%s] was resolved.',
                get_debug_type($enum),
            )),
        };

        if ($this->only !== null) {
            $rule->only($this->only);
        }

        if ($this->except !== null) {
            $rule->except($this->except);
        }

        return $rule;
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static(new EnumRule($parameters[0]));
    }
}
