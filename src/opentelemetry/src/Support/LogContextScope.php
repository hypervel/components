<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Log\Context\Repository;
use OpenTelemetry\API\Trace\SpanContextInterface;

final class LogContextScope
{
    private bool $closed = false;

    /**
     * Create a logging-context scope.
     */
    private function __construct(
        private Repository $context,
        private string $traceIdKey,
        private string $spanIdKey,
        private bool $hadTraceId,
        private mixed $previousTraceId,
        private bool $hadSpanId,
        private mixed $previousSpanId,
    ) {
    }

    /**
     * Activate trace correlation in Hypervel's logging context.
     */
    public static function activate(
        bool $enabled,
        SpanContextInterface $spanContext,
        string $traceIdKey,
        string $spanIdKey,
    ): ?self {
        if (! $enabled || ! $spanContext->isValid()) {
            return null;
        }

        $context = Repository::getInstance();
        $scope = new self(
            $context,
            $traceIdKey,
            $spanIdKey,
            $context->has($traceIdKey),
            $context->get($traceIdKey),
            $context->has($spanIdKey),
            $context->get($spanIdKey),
        );
        $context->add([
            $traceIdKey => $spanContext->getTraceId(),
            $spanIdKey => $spanContext->getSpanId(),
        ]);

        return $scope;
    }

    /**
     * Restore the previous logging context.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->restore($this->traceIdKey, $this->hadTraceId, $this->previousTraceId);
        $this->restore($this->spanIdKey, $this->hadSpanId, $this->previousSpanId);
    }

    /**
     * Restore or remove one prior context value.
     */
    private function restore(string $key, bool $existed, mixed $value): void
    {
        if ($existed) {
            $this->context->add($key, $value);
        } else {
            $this->context->forget($key);
        }
    }
}
