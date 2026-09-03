<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Extensions extends StringValidationAttribute
{
    protected array $extensions;

    /**
     * Create an extensions rule attribute.
     */
    public function __construct(string|array|ExternalReference ...$extensions)
    {
        $this->extensions = Arr::flatten($extensions);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'extensions';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->extensions];
    }
}
