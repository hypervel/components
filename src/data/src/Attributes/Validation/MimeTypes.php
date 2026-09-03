<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Support\Validation\References\ExternalReference;
use Hypervel\Support\Arr;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MimeTypes extends StringValidationAttribute
{
    protected array $mimeTypes;

    /**
     * Create a new MIME-types validation attribute.
     */
    public function __construct(string|array|ExternalReference ...$mimeTypes)
    {
        $this->mimeTypes = Arr::flatten($mimeTypes);
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'mimetypes';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        return [$this->mimeTypes];
    }
}
