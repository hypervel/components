<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Exceptions;

use Hypervel\Saloon\Http\PendingRequest;

class NoMockResponseFoundException extends SaloonException
{
    /**
     * Create a missing mock response exception.
     */
    public function __construct(PendingRequest $pendingRequest)
    {
        parent::__construct(sprintf('Saloon could not find a mock response for [%s]. Consider using a wildcard URL mock or a connector mock.', $pendingRequest->uri()));
    }
}
