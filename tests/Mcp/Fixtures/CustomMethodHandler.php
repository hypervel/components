<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class CustomMethodHandler implements Method
{
    /**
     * Create the custom method handler.
     */
    public function __construct()
    {
    }

    /**
     * Return the custom method result.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, ['message' => 'Custom method executed successfully!']);
    }
}
