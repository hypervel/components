<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataProperty;

class CannotCreateData extends Exception
{
    /**
     * Create an exception for a source without a normalizer.
     *
     * @param class-string $dataClass
     */
    public static function noNormalizerFound(string $dataClass, mixed $value): self
    {
        return new self(
            "Could not create data class [{$dataClass}] from value of type [" . get_debug_type($value)
            . ']: no normalizer accepted the value.'
        );
    }

    /**
     * Create an exception for missing constructor arguments.
     *
     * @param array<string, mixed> $parameters
     */
    public static function constructorMissingParameters(
        DataClass $dataClass,
        array $parameters,
    ): self {
        $given = array_keys($parameters);
        $missing = [];
        $required = 0;

        foreach ($dataClass->constructorParameters as $parameter) {
            if ($parameter->contextualAttribute !== null || $parameter->hasDefaultValue) {
                continue;
            }

            ++$required;

            if (! array_key_exists($parameter->name, $parameters)) {
                $missing[] = $parameter->name;
            }
        }

        $message = "Could not create data class [{$dataClass->name}]: its constructor requires "
            . $required . ' payload parameters and ' . count($given) . ' were supplied.';

        if ($given !== []) {
            $message .= ' Parameters supplied: ' . implode(', ', $given) . '.';
        }

        return new self($message . ' Parameters missing: ' . implode(', ', $missing) . '.');
    }

    /**
     * Create an exception for ordinary construction through a non-public constructor.
     */
    public static function nonPublicConstructor(DataClass $dataClass): self
    {
        $visibility = $dataClass->constructor?->isPrivate() ? 'private' : 'protected';

        return new self(
            "Could not create data class [{$dataClass->name}] because its constructor is {$visibility} and no "
            . 'matching named factory returned an instance. Return the target instance from a matching public '
            . 'static from* method or make the constructor public.'
        );
    }

    /**
     * Create an exception for a missing unbound property value.
     */
    public static function propertyMissing(DataClass $dataClass, DataProperty $property): self
    {
        return new self(
            "Could not create data class [{$dataClass->name}]: required property "
            . "[{$property->className}::\${$property->name}] is missing."
        );
    }

    /**
     * Create an exception for an ambiguous data-object union.
     *
     * @param list<class-string> $candidates
     */
    public static function ambiguousDataObjectUnion(
        DataProperty $property,
        array $candidates,
    ): self {
        return new self(
            "Could not create property [{$property->className}::\${$property->name}] from an ambiguous "
            . 'data-object union [' . implode(', ', $candidates) . ']. Supply an existing instance or define '
            . 'an explicit cast, morph discriminator, or named factory.'
        );
    }

    /**
     * Create an exception for an invalid after-creation replacement.
     */
    public static function invalidAfterCreationResult(DataClass $dataClass, mixed $value): self
    {
        return new self(
            "Could not create data class [{$dataClass->name}]: an after-creation hook returned ["
            . get_debug_type($value) . "] instead of an instance of [{$dataClass->name}]."
        );
    }
}
