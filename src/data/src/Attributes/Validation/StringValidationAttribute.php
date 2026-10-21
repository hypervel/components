<?php

declare(strict_types=1);

namespace Hypervel\Data\Attributes\Validation;

use Hypervel\Data\Exceptions\CannotBuildValidationRule;
use Hypervel\Support\ClassMetadataCache;

abstract class StringValidationAttribute extends ValidationAttribute
{
    /**
     * Get the rule parameters.
     */
    abstract public function parameters(): array;

    /**
     * Create the attribute from parsed string parameters.
     */
    public static function create(string ...$parameters): static
    {
        $constructor = ClassMetadataCache::reflectClass(static::class)->getConstructor();

        // PHP ignores surplus arguments, so a parameter the attribute cannot hold would be lost.
        if (! ($constructor?->isVariadic() ?? false)
            && count($parameters) > ($constructor?->getNumberOfParameters() ?? 0)
        ) {
            throw CannotBuildValidationRule::create(
                'The [' . static::keyword() . '] attribute cannot hold ' . count($parameters) . ' parameters.',
            );
        }

        return new static(...$parameters);
    }
}
