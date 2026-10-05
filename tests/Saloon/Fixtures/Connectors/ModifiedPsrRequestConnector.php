<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use GuzzleHttp\Psr7\Uri;
use Hypervel\Saloon\Http\PendingRequest;
use Psr\Http\Message\RequestInterface;

class ModifiedPsrRequestConnector extends TestConnector
{
    /**
     * Modify the final PSR request.
     */
    public function handlePsrRequest(RequestInterface $request, PendingRequest $pendingRequest): RequestInterface
    {
        return $request->withUri(new Uri('https://google.com'));
    }
}
