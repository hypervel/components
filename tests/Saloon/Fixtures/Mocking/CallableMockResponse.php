<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Mocking;

use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;

class CallableMockResponse
{
    /**
     * Create the mock response for the pending request.
     */
    public function __invoke(PendingRequest $pendingRequest): MockResponse
    {
        return new MockResponse(['request_class' => $pendingRequest->request()::class], 200);
    }
}
