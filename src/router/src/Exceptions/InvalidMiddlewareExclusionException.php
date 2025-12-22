<?php

declare(strict_types=1);

namespace Hypervel\Router\Exceptions;

use InvalidArgumentException;

class InvalidMiddlewareExclusionException extends InvalidArgumentException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $middleware)
    {
        $name = explode(':', $middleware, 2)[0];

        parent::__construct(
            "Middleware exclusion '{$middleware}' should not contain parameters. Use '{$name}' instead."
        );
    }
}
