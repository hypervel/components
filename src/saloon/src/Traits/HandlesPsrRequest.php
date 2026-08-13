<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits;

use Hypervel\Saloon\Http\PendingRequest;
use Psr\Http\Message\RequestInterface;

trait HandlesPsrRequest
{
    /**
     * Handle the final PSR request before it is sent.
     */
    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        return $request;
    }
}
