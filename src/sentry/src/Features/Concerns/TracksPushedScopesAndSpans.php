<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Sentry\Integration;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * Track pushed scopes and spans using coroutine-local storage.
 *
 * State is stored in coroutine Context (keyed by the using class name) so that
 * singleton features sharing this trait remain safe under concurrent coroutines.
 */
trait TracksPushedScopesAndSpans
{
    /**
     * Push a span onto the coroutine-local stack and set it as current on the hub.
     */
    protected function pushSpan(Span $span): void
    {
        $hub = SentrySdk::getCurrentHub();

        $parentStack = CoroutineContext::get($this->contextKey('parent_spans'), []);
        $parentStack[] = $hub->getSpan();
        CoroutineContext::set($this->contextKey('parent_spans'), $parentStack);

        $hub->setSpan($span);

        $currentStack = CoroutineContext::get($this->contextKey('current_spans'), []);
        $currentStack[] = $span;
        CoroutineContext::set($this->contextKey('current_spans'), $currentStack);
    }

    /**
     * Push a scope onto the hub and track the count in coroutine-local storage.
     */
    protected function pushScope(): void
    {
        SentrySdk::getCurrentHub()->pushScope();

        $count = CoroutineContext::get($this->contextKey('scope_count'), 0);
        CoroutineContext::set($this->contextKey('scope_count'), $count + 1);
    }

    /**
     * Pop a span from the coroutine-local stack and restore the parent span.
     */
    protected function maybePopSpan(): ?Span
    {
        $currentStack = CoroutineContext::get($this->contextKey('current_spans'), []);

        if ($currentStack === []) {
            return null;
        }

        $parentStack = CoroutineContext::get($this->contextKey('parent_spans'), []);
        $parent = array_pop($parentStack);
        CoroutineContext::set($this->contextKey('parent_spans'), $parentStack);

        SentrySdk::getCurrentHub()->setSpan($parent);

        $span = array_pop($currentStack);
        CoroutineContext::set($this->contextKey('current_spans'), $currentStack);

        return $span;
    }

    /**
     * Pop a scope from the hub if one was pushed in this coroutine.
     */
    protected function maybePopScope(): void
    {
        Integration::flushEvents();

        $count = CoroutineContext::get($this->contextKey('scope_count'), 0);

        if ($count === 0) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();

        CoroutineContext::set($this->contextKey('scope_count'), $count - 1);
    }

    /**
     * Finish the current span if one exists on the coroutine-local stack.
     */
    protected function maybeFinishSpan(?SpanStatus $status = null): ?Span
    {
        $span = $this->maybePopSpan();

        if ($span === null) {
            return null;
        }

        if ($status !== null) {
            $span->setStatus($status);
        }

        $span->finish();

        return $span;
    }

    /**
     * Context key prefix for per-class span tracking state.
     */
    public const SPANS_CONTEXT_PREFIX = '__sentry.spans.';

    /**
     * Build a coroutine Context key scoped to this class.
     */
    private function contextKey(string $suffix): string
    {
        return self::SPANS_CONTEXT_PREFIX . static::class . '.' . $suffix;
    }
}
