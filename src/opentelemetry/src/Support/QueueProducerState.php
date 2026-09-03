<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;

final readonly class QueueProducerState
{
    /**
     * Create producer telemetry state.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public array $attributes,
    ) {
    }
}
