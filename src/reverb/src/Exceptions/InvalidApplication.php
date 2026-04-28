<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Exceptions;

use Exception;

class InvalidApplication extends Exception
{
    /**
     * @var string
     */
    protected $message = 'Application does not exist';
}
