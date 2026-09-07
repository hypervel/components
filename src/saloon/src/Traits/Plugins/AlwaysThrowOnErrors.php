<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Plugins;

use Hypervel\Saloon\Enums\PipeOrder;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;

trait AlwaysThrowOnErrors
{
    /**
     * Apply automatic response exception handling.
     */
    public function bootAlwaysThrowOnErrors(PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            static fn (Response $response): Response => $response->throw(),
            order: PipeOrder::Last,
        );
    }
}
