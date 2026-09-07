<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Contracts\Config\Repository;
use OpenTelemetry\API\Trace\SpanContextInterface;

class LogContextScopeFactory
{
    protected bool $enabled;

    protected string $traceIdKey;

    protected string $spanIdKey;

    /**
     * Create a log-context scope factory.
     */
    public function __construct(Repository $config)
    {
        $this->enabled = $config->boolean('opentelemetry.log_context.enabled');
        $this->traceIdKey = $config->string('opentelemetry.log_context.trace_id_key');
        $this->spanIdKey = $config->string('opentelemetry.log_context.span_id_key');
    }

    /**
     * Activate correlation for one span context.
     */
    public function activate(SpanContextInterface $spanContext): ?LogContextScope
    {
        return LogContextScope::activate(
            $this->enabled,
            $spanContext,
            $this->traceIdKey,
            $this->spanIdKey,
        );
    }
}
