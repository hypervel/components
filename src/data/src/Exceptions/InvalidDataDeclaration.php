<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\DataParameter;
use LogicException;

class InvalidDataDeclaration extends LogicException
{
    /**
     * Create an exception for a non-public promoted property.
     *
     * @param class-string $class
     */
    public static function nonPublicPromotedProperty(
        string $class,
        DataParameter $parameter,
    ): self {
        $declaringClass = $parameter->reflection->getDeclaringClass()?->getName() ?? $class;

        return new self(
            "Data class [{$class}] promotes non-public property [{$declaringClass}::\${$parameter->name}]. "
            . 'Promoted data properties must be public.'
        );
    }

    /**
     * Create an exception for a constructor parameter without a data property.
     *
     * @param class-string $class
     */
    public static function missingDataProperty(string $class, DataParameter $parameter): self
    {
        $declaringClass = $parameter->reflection->getDeclaringClass()?->getName() ?? $class;

        return new self(
            "Data class [{$class}] constructor parameter [{$declaringClass}::\${$parameter->name}] has no "
            . 'corresponding public data property or contextual attribute. Promote the parameter, declare a '
            . 'public property with the same name, or use a named factory.'
        );
    }

    /**
     * Create an exception for a non-promoted readonly input property.
     *
     * @param class-string $class
     */
    public static function unassignableReadonlyProperty(string $class, DataProperty $property): self
    {
        return new self(
            "Data class [{$class}] cannot assign unbound readonly property "
            . "[{$property->className}::\${$property->name}]. Promote the property, declare a same-name "
            . 'constructor parameter, or mark it as computed.'
        );
    }

    /**
     * Create an exception for an output-only constructor property.
     *
     * @param class-string $class
     */
    public static function computedConstructorProperty(string $class, DataProperty $property): self
    {
        return new self(
            "Data class [{$class}] declares output-only property [{$property->className}::\${$property->name}] "
            . 'as a constructor parameter. Remove the computed declaration or initialize the property from other parameters.'
        );
    }

    /**
     * Create an exception for a contextual parameter conflicting with a data property.
     *
     * @param class-string $class
     */
    public static function contextualParameterConflictsWithProperty(
        string $class,
        DataParameter $parameter,
        DataProperty $property,
    ): self {
        $declaringClass = $parameter->reflection->getDeclaringClass()?->getName() ?? $class;

        return new self(
            "Data class [{$class}] contextual constructor parameter [{$declaringClass}::\${$parameter->name}] "
            . "conflicts with public data property [{$property->className}::\${$property->name}]. Promote the "
            . 'attributed parameter when it is the data property, or rename it when it is a separate dependency.'
        );
    }

    /**
     * Create an exception for a variadic creation context.
     *
     * @param class-string $class
     */
    public static function variadicCreationContext(
        string $class,
        string $method,
        string $parameter,
    ): self {
        return new self(
            "Data factory [{$class}::{$method}] cannot declare variadic CreationContext parameter [\${$parameter}]. "
            . 'Declare a single CreationContext parameter instead.'
        );
    }

    /**
     * Create an exception for a duplicate input path.
     *
     * @param class-string $class
     */
    public static function duplicateInputPath(
        string $class,
        string|int $path,
        DataProperty $firstProperty,
        DataProperty $secondProperty,
    ): self {
        return new self(
            "Data class [{$class}] has properties [{$firstProperty->className}::\${$firstProperty->name}] and "
            . "[{$secondProperty->className}::\${$secondProperty->name}] that both resolve to input path [{$path}]. "
            . 'Give each property a unique input path. If one value is derived from another, use a computed property with a distinct name.'
        );
    }

    /**
     * Create an exception for a duplicate output key.
     *
     * @param class-string $class
     */
    public static function duplicateOutputKey(
        string $class,
        string|int $key,
        DataProperty $firstProperty,
        DataProperty $secondProperty,
    ): self {
        return new self(
            "Data class [{$class}] has properties [{$firstProperty->className}::\${$firstProperty->name}] and "
            . "[{$secondProperty->className}::\${$secondProperty->name}] that both resolve to output key [{$key}]. "
            . 'Give each property a unique output key.'
        );
    }
}
