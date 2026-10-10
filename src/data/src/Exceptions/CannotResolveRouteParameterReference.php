<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class CannotResolveRouteParameterReference extends Exception
{
    /**
     * Create an exception for a missing route parameter.
     */
    public static function parameterNotFound(string $name, ?string $property): self
    {
        return $property !== null
            ? new self("Cannot find route parameter {$name} with property {$property}")
            : new self("Cannot find route parameter {$name}");
    }

    /**
     * Create an exception for a missing property on a route parameter.
     */
    public static function propertyOnParameterNotFound(string $name, string $property): self
    {
        return new self("Cannot find property {$property} in route parameter {$name}");
    }
}
