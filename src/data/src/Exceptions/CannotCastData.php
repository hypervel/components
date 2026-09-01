<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CannotCastData extends Exception
{
    /**
     * Create an exception for a non-array cast value.
     */
    public static function shouldBeArray(string $modelClass, string $attribute): self
    {
        return new self("Attribute `{$attribute}` of model `{$modelClass}` should be an array");
    }

    /**
     * Create an exception for a non-data cast value.
     */
    public static function shouldBeData(string $modelClass, string $attribute): self
    {
        return new self("Attribute `{$attribute}` of model `{$modelClass}` should be a Data object");
    }

    /**
     * Create an exception for a non-transformable data cast value.
     */
    public static function shouldBeTransformableData(string $modelClass, string $attribute): self
    {
        return new self("Attribute `{$attribute}` of model `{$modelClass}` should be a transformable Data object");
    }

    /**
     * Create an exception for a missing collection item type.
     */
    public static function dataCollectionTypeRequired(): self
    {
        return new self('When casting a Data Collection the type of Data should be provided as an argument');
    }
}
