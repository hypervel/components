<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\AnyOf as BaseAnyOf;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class AnyOf extends ObjectValidationAttribute
{
    /**
     * Create an any-of rule attribute.
     */
    public function __construct(protected array $rules)
    {
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        return new BaseAnyOf($this->rules);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'any_of';
    }

    /**
     * Reject string-based any-of rule construction.
     */
    public static function create(string ...$parameters): static
    {
        throw CannotBuildValidationRule::create('Cannot create an any-of rule from string parameters.');
    }
}
