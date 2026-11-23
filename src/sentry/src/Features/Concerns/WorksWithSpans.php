<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Swoole\Coroutine\CanceledException;
use Throwable;

trait WorksWithSpans
{
    /**
     * Get the current span if it is sampled.
     */
    protected function getParentSpanIfSampled(): ?Span
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If the span is not available or not sampled we don't need to do anything
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return null;
        }

        return $parentSpan;
    }

    /**
     * Call the callback with the current span if it is sampled.
     *
     * @param callable(Span $parentSpan): void $callback
     */
    protected function withParentSpanIfSampled(callable $callback): void
    {
        $parentSpan = $this->getParentSpanIfSampled();

        if ($parentSpan === null) {
            return;
        }

        $callback($parentSpan);
    }

    /**
     * Get the status of an operation that cancellation stopped.
     *
     * A direct cancellation with no earlier failure leaves the status unset: it is
     * control flow, and the SDK has no canceled status. A known failure, or a
     * cleanup failure carrying the cancellation, is an error.
     */
    protected function cancellationStatus(Throwable $cancellation, ?Throwable $exception = null): ?SpanStatus
    {
        // @phpstan-ignore hypervel.cancellation.exactType (only a direct cancellation is not a failure; a cleanup failure carrying it is recorded as one)
        return $exception === null && $cancellation instanceof CanceledException
            ? null
            : SpanStatus::internalError();
    }
}
