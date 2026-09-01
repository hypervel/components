<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Swoole\WebSocket\Frame;

class WebSocketTelemetryState
{
    /**
     * Create WebSocket message telemetry state.
     *
     * @param array<string, int|string> $attributes
     */
    public function __construct(
        public Frame $frame,
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $attributes,
    ) {
    }
}
