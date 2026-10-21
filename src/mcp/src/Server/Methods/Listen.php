<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Generator;
use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class Listen implements Method
{
    /**
     * Acknowledge and complete a subscription request.
     *
     * @return Generator<int, JsonRpcResponse>
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator
    {
        yield JsonRpcResponse::notification('notifications/subscriptions/acknowledged', [
            '_meta' => [MetaKey::SUBSCRIPTION_ID->value => $request->id],
            'notifications' => (object) [],
        ]);

        yield JsonRpcResponse::result($request->id, [
            '_meta' => [MetaKey::SUBSCRIPTION_ID->value => $request->id],
        ]);
    }
}
