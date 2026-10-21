<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Generator;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\Contracts\Errable;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\ToolInvoker;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class CallTool implements Errable, Method
{
    /**
     * Call the requested tool.
     *
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if (is_null($request->get('name'))) {
            throw new JsonRpcException(
                'Missing [name] parameter.',
                -32602,
                $request->id,
            );
        }

        if (! is_string($request->get('name'))) {
            throw new JsonRpcException('The [name] parameter must be a string.', -32602, $request->id);
        }

        $tool = $context
            ->tools()
            ->first(
                fn (Tool $tool): bool => $tool->name() === $request->params['name'],
                fn (): never => throw new JsonRpcException(
                    "Tool [{$request->params['name']}] not found.",
                    -32602,
                    $request->id,
                )
            );

        return (new ToolInvoker)->invoke($tool, $request);
    }
}
