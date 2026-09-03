<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Contracts\View\View;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;

class ViewTelemetryState
{
    /**
     * Create view-render telemetry state.
     *
     * @param array<string, string> $attributes
     */
    public function __construct(
        public View $view,
        public int $startedAt,
        public ContextInterface $context,
        public ?SpanInterface $span,
        public ?ScopeInterface $scope,
        public ?LogContextScope $logContextScope,
        public array $attributes,
    ) {
    }
}
