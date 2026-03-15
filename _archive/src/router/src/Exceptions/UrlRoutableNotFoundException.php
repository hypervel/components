<?php

declare(strict_types=1);

namespace Hypervel\Router\Exceptions;

use RuntimeException;

class UrlRoutableNotFoundException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $class, string $routeKey)
    {
        parent::__construct("No query results for url routable [{$class}] {$routeKey}.");
    }
}
