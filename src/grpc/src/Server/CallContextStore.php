<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Context\CoroutineContext;
use LogicException;

/**
 * @internal
 */
class CallContextStore
{
    private const CALL_CONTEXT_KEY = '__grpc.call';

    /**
     * Store the active server call context.
     */
    public function set(ServerCallContext $context): void
    {
        CoroutineContext::set(self::CALL_CONTEXT_KEY, $context);
    }

    /**
     * Return the active server call context.
     */
    public function get(): ServerCallContext
    {
        /** @var null|ServerCallContext $context */
        $context = CoroutineContext::get(self::CALL_CONTEXT_KEY);

        return $context ?? throw new LogicException(
            'No gRPC server call is active in the current coroutine.',
        );
    }

    /**
     * Forget the active server call context.
     */
    public function forget(): void
    {
        CoroutineContext::forget(self::CALL_CONTEXT_KEY);
    }
}
