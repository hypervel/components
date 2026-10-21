<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Http\PendingRequest;
use Psr\Http\Message\RequestInterface;

class ModifiedPsrUserRequest extends UserRequest
{
    /**
     * Modify the final PSR request.
     */
    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        return $request->withHeader('X-Howdy', 'Yeehaw');
    }
}
