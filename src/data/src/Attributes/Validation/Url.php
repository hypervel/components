<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Url extends StringValidationAttribute
{
    protected array $protocols;

    /**
     * Create a URL rule attribute.
     */
    public function __construct(
        string|array|ExternalReference ...$protocols,
    ) {
        $this->protocols = Arr::flatten($protocols);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'url';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return $this->protocols;
    }
}
