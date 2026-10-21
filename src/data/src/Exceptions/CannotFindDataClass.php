<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Contracts\BaseData;

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
     * Create an exception for a source without a valid data class.
     */
    public static function forSource(string $source, mixed $dataClass): self
    {
        if ($dataClass === null) {
            return new self("Class [{$source}] must declare a [\$dataClass] property or a [dataClass()] method to use [getData()].");
        }

        $declared = is_string($dataClass) ? $dataClass : get_debug_type($dataClass);

        return new self("Class [{$source}] declared data class [{$declared}], which must implement [" . BaseData::class . '].');
    }
}
