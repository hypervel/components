<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency\Fixtures;

use Exception;

class ExceptionWithFalseyParam extends Exception
{
    /**
     * Create an exception with a falsey parameter.
     */
    public function __construct(public int|bool|string $value)
    {
        parent::__construct('Exception with falsey parameter');
    }
}
