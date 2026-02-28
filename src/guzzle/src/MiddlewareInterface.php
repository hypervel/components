<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

interface MiddlewareInterface
{
    /**
     * Get the middleware callable.
     */
    public function getMiddleware(): callable;
}
