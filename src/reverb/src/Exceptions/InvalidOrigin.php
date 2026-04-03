<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Exceptions;

use Exception;

class InvalidOrigin extends Exception
{
    /**
     * @var string
     */
    protected $message = 'Origin not allowed';
}
