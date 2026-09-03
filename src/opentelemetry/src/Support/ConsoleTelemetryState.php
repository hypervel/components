<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Console\Command;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

class ConsoleTelemetryState
{
    /**
     * Create console-command telemetry state.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public Command $command,
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $attributes,
    ) {
    }
}
