<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Pagination\CursorPaginator;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class ListResourceTemplates implements Method
{
    /**
     * Handle a resource template listing request.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $paginator = new CursorPaginator(
            items: $context->resourceTemplates(),
            perPage: $context->perPage($request->perPage()),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('resourceTemplates'));
    }
}
