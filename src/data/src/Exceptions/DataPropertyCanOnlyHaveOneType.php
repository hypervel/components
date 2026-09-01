<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Support\DataProperty;

class DataPropertyCanOnlyHaveOneType extends Exception
{
    /**
     * Create an exception for an ambiguous empty data property.
     */
    public static function create(DataProperty $property): self
    {
        return new self(
            sprintf(
                'Empty data property [%s::$%s] has multiple types. Supply its value through the $extra argument to empty().',
                $property->className,
                $property->name,
            ),
        );
    }
}
