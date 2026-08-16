<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts;

use Hypervel\Saloon\Http\Response;

interface ResponseMiddleware
{
    /**
     * Handle an incoming response.
     */
    public function __invoke(Response $response): ?Response;
}
