<?php

declare(strict_types=1);

namespace Hypervel\Data\Exceptions;

use Exception;

class InvalidDataCollectionOperation extends Exception
{
    /**
     * Create an invalid collection operation exception.
     */
    public static function create(): self
    {
        return new self('Cannot execute an array operation on this type of collection');
    }
}
