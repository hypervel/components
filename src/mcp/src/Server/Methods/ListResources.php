<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Pagination\CursorPaginator;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Skill;
use Hypervel\Mcp\Server\Skills\SkillResource;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class ListResources implements Method
{
    /**
     * Handle a resource listing request.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $resources = $context->resources()->concat(
            $context->skills()->map(fn (Skill $skill): SkillResource => $skill->resource('SKILL.md')),
        );

        $paginator = new CursorPaginator(
            items: $resources,
            perPage: $context->perPage($request->perPage()),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('resources'));
    }
}
