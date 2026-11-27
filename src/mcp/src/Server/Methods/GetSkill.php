<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Skill;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class GetSkill implements Method
{
    /**
     * Fetch a registered skill by its SKILL.md URI.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $uri = $request->get('uri');

        if ($uri !== null && ! is_string($uri)) {
            throw new JsonRpcException('The [uri] parameter must be a string.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $skill = $context->skills()->first(fn (Skill $skill): bool => $skill->uri() === $uri);

        if (! $skill instanceof Skill) {
            throw new JsonRpcException('The [uri] parameter must be the URI of a registered skill.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        return JsonRpcResponse::result($request->id, ['skill' => $skill->toArray()]);
    }
}
