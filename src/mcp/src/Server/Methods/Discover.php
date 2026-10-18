<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class Discover implements Method
{
    /**
     * Describe the server's supported versions and capabilities.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'supportedVersions' => $context->supportedProtocolVersions,
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'instructions' => $context->instructions,
        ]);
    }
}
