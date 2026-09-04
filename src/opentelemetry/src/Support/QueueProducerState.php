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
     * The UUID stays with the state so exact-payload removal can clear its fallback index.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public array $attributes,
        public ?string $uuid,
    ) {
    }
}
