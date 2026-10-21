<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Plugins;

use Hypervel\Saloon\Http\PendingRequest;

trait WithBootTestPlugin
{
    /**
     * Boot a test handler that adds a simple header to the response.
     */
    public function bootWithBootTestPlugin(PendingRequest $pendingRequest): void
    {
        $request = $pendingRequest->request();

        $pendingRequest->withHeader('X-Plugin-User-Id', $request->userId);
        $pendingRequest->withHeader('X-Plugin-Group-Id', $request->groupId);
    }
}
