<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

class QueueConsumerState
{
    public bool $completed = false;

    public ?Throwable $exception = null;

    public ?string $errorType = null;

    /**
     * Create consumer telemetry state.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $attributes,
    ) {
    }
}
