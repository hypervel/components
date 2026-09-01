<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;

class CannotCreateCastAttribute extends Exception
{
    /**
     * Create an exception for an invalid cast class.
     */
    public static function notACast(string $class): self
    {
        return new self("Cast class [{$class}] must implement [" . Cast::class . '].');
    }

    /**
     * Create an exception for an invalid castable class.
     */
    public static function notACastable(string $class): self
    {
        return new self("Castable class [{$class}] must implement [" . Castable::class . '].');
    }
}
