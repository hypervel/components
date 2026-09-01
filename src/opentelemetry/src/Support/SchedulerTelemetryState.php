<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Console\Scheduling\Event;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

class SchedulerTelemetryState
{
    /**
     * Create scheduled-task telemetry state.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public Event $task,
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $attributes,
    ) {
    }
}
