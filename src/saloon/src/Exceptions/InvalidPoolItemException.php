<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

class InvalidPoolItemException extends SaloonException
{
    /**
     * Create an invalid pool item exception.
     */
    public function __construct()
    {
        parent::__construct('Saloon pools only accept Request instances. You may provide any iterable or a callable that returns an iterable.');
    }
}
