<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Attribute;
use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Data\Support\Validation\References\ExternalReference;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Distinct extends StringValidationAttribute
{
    public const string Strict = 'strict';

    public const string IgnoreCase = 'ignore_case';

    /**
     * Create a new distinct validation attribute.
     */
    public function __construct(protected string|ExternalReference|null $mode = null)
    {
    }

    /**
     * Get the Validator rule keyword.
     */
    public static function keyword(): string
    {
        return 'distinct';
    }

    /**
     * Get the rule parameters.
     */
    public function parameters(): array
    {
        $mode = $this->normalizePossibleExternalReferenceParameter($this->mode);

        if ($mode === null) {
            return [];
        }

        if (! is_string($mode) || ! in_array($mode, [self::IgnoreCase, self::Strict], true)) {
            throw CannotBuildValidationRule::create('Distinct mode should be ignore_case or strict.');
        }

        return [$mode];
    }
}
