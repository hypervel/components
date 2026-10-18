<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;

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
}
