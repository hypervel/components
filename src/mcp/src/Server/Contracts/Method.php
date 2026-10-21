<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

interface Method
{
    /**
     * Handle a protocol request within its server context.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse;
}
