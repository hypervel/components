<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;

/**
 * Resolve operation origins through one shared context-key identity.
 *
 * OpenTelemetry compares custom context keys by object identity, so the
 * container must supply the same instance to every producer and resolver.
 */
class OperationOrigin
{
    public const string REQUEST = 'request';

    public const string JOB = 'job';

    public const string CONSOLE = 'console';

    public const string SCHEDULE = 'schedule';

    public const string WEBSOCKET = 'websocket';

    public const string RPC = 'rpc';

    public const string PROCESS = 'process';

    protected readonly ContextKeyInterface $key;

    /**
     * Create an operation-origin resolver.
     */
    public function __construct()
    {
        $this->key = Context::createKey('hypervel.opentelemetry.operation_origin');
    }

    /**
     * Store an operation origin in an OpenTelemetry context.
     */
    public function withOrigin(ContextInterface $context, string $origin): ContextInterface
    {
        return $context->with($this->key, $origin);
    }

    /**
     * Resolve the truthful operation origin available to an exception report.
     */
    public function resolve(ContextInterface $context, ?ProcessIdentity $identity = null): ?string
    {
        if (is_string($origin = $context->get($this->key))) {
            return $origin;
        }

        if (RequestContext::has()) {
            return self::REQUEST;
        }

        if (CoroutineContext::has(WebSocketContext::FD)) {
            return self::WEBSOCKET;
        }

        return match ($identity?->type) {
            ProcessIdentity::CLI => self::CONSOLE,
            ProcessIdentity::PROCESS => self::PROCESS,
            default => null,
        };
    }
}
