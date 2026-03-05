<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Traits;

use Hypervel\Context\Context;
use Hypervel\Sentry\Integrations\Integration;
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

        $parentStack = Context::get($this->contextKey('parent_spans'), []);
        $parentStack[] = $hub->getSpan();
        Context::set($this->contextKey('parent_spans'), $parentStack);

        $hub->setSpan($span);

        $currentStack = Context::get($this->contextKey('current_spans'), []);
        $currentStack[] = $span;
        Context::set($this->contextKey('current_spans'), $currentStack);
    }

    /**
     * Push a scope onto the hub and track the count in coroutine-local storage.
     */
    protected function pushScope(): void
    {
        SentrySdk::getCurrentHub()->pushScope();

        $count = Context::get($this->contextKey('scope_count'), 0);
        Context::set($this->contextKey('scope_count'), $count + 1);
    }

    /**
     * Pop a span from the coroutine-local stack and restore the parent span.
     */
    protected function maybePopSpan(): ?Span
    {
        $currentStack = Context::get($this->contextKey('current_spans'), []);

        if ($currentStack === []) {
            return null;
        }

        $parentStack = Context::get($this->contextKey('parent_spans'), []);
        $parent = array_pop($parentStack);
        Context::set($this->contextKey('parent_spans'), $parentStack);

        SentrySdk::getCurrentHub()->setSpan($parent);

        $span = array_pop($currentStack);
        Context::set($this->contextKey('current_spans'), $currentStack);

        return $span;
    }

    /**
     * Pop a scope from the hub if one was pushed in this coroutine.
     */
    protected function maybePopScope(): void
    {
        Integration::flushEvents();

        $count = Context::get($this->contextKey('scope_count'), 0);

        if ($count === 0) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();

        Context::set($this->contextKey('scope_count'), $count - 1);
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
     * Build a coroutine Context key scoped to this class.
     */
    private function contextKey(string $suffix): string
    {
        return '__sentry.spans.' . static::class . '.' . $suffix;
    }
}
