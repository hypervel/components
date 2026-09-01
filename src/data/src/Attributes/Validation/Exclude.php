<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\ExcludeIf;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Exclude extends ObjectValidationAttribute
{
    /**
     * Create a new exclude validation attribute.
     */
    public function __construct(protected ?ExcludeIf $rule = null)
    {
    }

    /**
     * Get the Validator rule object or string.
     */
    public function getRule(ValidationPath $path): object|string
    {
        return $this->rule ?? self::keyword();
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'exclude';
    }

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        return new static();
    }
}
