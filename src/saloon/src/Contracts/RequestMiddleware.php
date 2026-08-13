<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Contracts;

use Hypervel\Saloon\Http\PendingRequest;

interface RequestMiddleware
{
    /**
     * Handle an outgoing request.
     */
    public function __invoke(PendingRequest $pendingRequest): ?FakeResponse;
}
