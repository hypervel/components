<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\ValidationPath;
use Hypervel\Validation\Rules\Can as BaseCan;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Can extends ObjectValidationAttribute
{
    protected array $arguments;

    /**
     * Create a can rule attribute.
     */
    public function __construct(protected string $ability, mixed ...$arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Get the Validator rule object.
     */
    public function getRule(ValidationPath $path): object|string
    {
        return new BaseCan($this->ability, $this->arguments);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'can';
    }

    /**
     * Reject string-based can rule construction.
     */
    public static function create(string ...$parameters): static
    {
        throw CannotBuildValidationRule::create('Cannot create a can rule from string parameters.');
    }
}
