<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

class GrpcTelemetryState
{
    /**
     * Create logical gRPC call telemetry state.
     *
     * @param array<string, int|string> $metricAttributes
     */
    public function __construct(
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $metricAttributes,
    ) {
    }
}
