<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Contracts\BaseData;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

class CannotFindDataClass extends Exception
{
    /**
     * Create an exception for an invalid data class.
     */
    public static function forClass(string $class): self
    {
        return new self("Class [{$class}] must implement [" . BaseData::class . '].');
    }

    /**
     * Create an exception for a declaration without a data class.
     */
    public static function forTypeable(ReflectionMethod|ReflectionProperty|ReflectionParameter|string $typeable): self
    {
        if (is_string($typeable)) {
            return new self("Cannot find a data class for type [{$typeable}].");
        }

        $class = $typeable->getDeclaringClass()?->getName() ?? 'unknown';

        $name = match (true) {
            $typeable instanceof ReflectionMethod => "method [{$class}::{$typeable->getName()}]",
            $typeable instanceof ReflectionProperty => "property [{$class}::\${$typeable->getName()}]",
            $typeable instanceof ReflectionParameter => "parameter [{$class}::{$typeable->getDeclaringFunction()->getName()}(\${$typeable->getName()})]",
        };

        return new self("Cannot find a data class for {$name}.");
    }
}
