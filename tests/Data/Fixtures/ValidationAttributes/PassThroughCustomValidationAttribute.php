<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\ValidationAttributes;

use Attribute;
use Hypervel\Data\Attributes\Validation\CustomValidationAttribute;
use Hypervel\Data\Support\Validation\ValidationPath;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class PassThroughCustomValidationAttribute extends CustomValidationAttribute
{
    /**
     * Create an attribute that returns the given rules.
     */
    public function __construct(
        private array|object|string $rules,
    ) {
    }

    /**
     * Get the rules given to the attribute.
     *
     * @return array<object|string>|object|string
     */
    public function getRules(ValidationPath $path): array|object|string
    {
        return $this->rules;
    }
}
