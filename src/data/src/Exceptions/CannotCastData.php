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
     * Create an exception for a non-transformable Eloquent data class.
     */
    public static function dataClassMustBeTransformable(string $dataClass): self
    {
        return new self(
            "Data class `{$dataClass}` should implement TransformableData to be used in an Eloquent cast",
        );
    }

    /**
     * Create an exception for a data value of the wrong class.
     */
    public static function shouldBeDataClass(
        string $modelClass,
        string $attribute,
        string $dataClass,
    ): self {
        return new self(
            "Attribute `{$attribute}` of model `{$modelClass}` should be an instance of `{$dataClass}`",
        );
    }

    /**
     * Create an exception for an invalid stored data representation.
     */
    public static function invalidStoredValue(string $modelClass, string $attribute): self
    {
        return new self(
            "Attribute `{$attribute}` of model `{$modelClass}` should contain a JSON object or array",
        );
    }

    /**
     * Create an exception for an invalid stored collection item.
     */
    public static function invalidStoredCollectionItem(
        string $modelClass,
        string $attribute,
        int|string $itemKey,
    ): self {
        return new self(
            "Item `{$itemKey}` in attribute `{$attribute}` of model `{$modelClass}` should contain a JSON object",
        );
    }

    /**
     * Create an exception for an invalid abstract data envelope.
     */
    public static function invalidMorphEnvelope(string $modelClass, string $attribute): self
    {
        return new self(
            "Attribute `{$attribute}` of model `{$modelClass}` should contain a data morph envelope",
        );
    }

    /**
     * Create an exception for an unknown data morph alias.
     */
    public static function unknownMorphAlias(string $alias, string $dataClass): self
    {
        return new self("Data morph alias `{$alias}` is not registered for `{$dataClass}`");
    }

    /**
     * Create an exception for an invalid data morph class.
     */
    public static function invalidMorphClass(string $class, string $dataClass): self
    {
        return new self(
            "Data morph class `{$class}` should be a concrete transformable subtype of `{$dataClass}`",
        );
    }

    /**
     * Create an exception for a data class without an enforced morph alias.
     */
    public static function morphAliasRequired(string $class): self
    {
        return new self("Data class `{$class}` should have an enforced morph alias");
    }

    /**
     * Create an exception for a missing collection item type.
     */
    public static function dataCollectionTypeRequired(): self
    {
        return new self('When casting a Data Collection the type of Data should be provided as an argument');
    }
}
