<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use RuntimeException;

class StrayRequestException extends RuntimeException
{
    public function __construct(string $uri)
    {
        parent::__construct('Attempted request to [' . $uri . '] without a matching fake.');
    }
}
