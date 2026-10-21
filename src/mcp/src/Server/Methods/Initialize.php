<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class Initialize implements Method
{
    /**
     * Negotiate a supported legacy protocol version.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $requested = $request->params['protocolVersion'] ?? null;

        $supported = ProtocolVersion::initializeSupported();

        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => in_array($requested, $supported, true)
                ? $requested
                : $supported[0],
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'serverInfo' => $context->implementation->toArray(),
            'instructions' => $context->instructions,
        ]);
    }
}
