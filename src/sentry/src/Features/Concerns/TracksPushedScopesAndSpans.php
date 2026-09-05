<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * Track pushed scopes and spans using coroutine-local storage.
 *
 * State is stored in coroutine Context (keyed by the using class name) so that
 * singleton features sharing this trait remain safe under concurrent coroutines.
 * Operation-local spans are tracked by key without becoming current on the Hub.
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
        $this->registerCleanup();
    }

    /**
     * Push a scope onto the hub and track the count in coroutine-local storage.
     */
    protected function pushScope(): Scope
    {
        $scope = SentrySdk::getCurrentHub()->pushScope();

        $count = CoroutineContext::get($this->contextKey('scope_count'), 0);
        CoroutineContext::set($this->contextKey('scope_count'), $count + 1);
        $this->registerCleanup();

        return $scope;
    }

    /**
     * Track an operation-local span without making it current on the Hub.
     */
    protected function trackLocalSpan(string $key, Span $span): void
    {
        $spans = CoroutineContext::get($this->contextKey('local_spans'), []);
        $spans[$key] = $span;
        CoroutineContext::set($this->contextKey('local_spans'), $spans);
        $this->registerCleanup();
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
     * Finish an operation-local span by key if one exists.
     */
    protected function maybeFinishLocalSpan(string $key, ?SpanStatus $status = null): ?Span
    {
        $spans = CoroutineContext::get($this->contextKey('local_spans'), []);
        $span = $spans[$key] ?? null;

        if ($span === null) {
            return null;
        }

        unset($spans[$key]);
        CoroutineContext::set($this->contextKey('local_spans'), $spans);

        if ($status !== null) {
            $span->setStatus($status);
        }

        $span->finish();

        return $span;
    }

    /**
     * Context key prefix for per-class span tracking state.
     */
    public const string SPANS_CONTEXT_PREFIX = '__sentry.spans.';

    /**
     * Build a coroutine Context key scoped to this class.
     */
    private function contextKey(string $suffix): string
    {
        return self::SPANS_CONTEXT_PREFIX . static::class . '.' . $suffix;
    }

    /**
     * Register cleanup for scopes and spans without terminal events.
     */
    private function registerCleanup(): void
    {
        if (! Coroutine::inCoroutine()) {
            return;
        }

        $cleanupKey = $this->contextKey('cleanup_registered');

        if (CoroutineContext::get($cleanupKey, false)) {
            return;
        }

        CoroutineContext::set($cleanupKey, true);
        Coroutine::defer(function () use ($cleanupKey): void {
            foreach (CoroutineContext::get($this->contextKey('local_spans'), []) as $span) {
                if ($span->getEndTimestamp() === null) {
                    $span->setStatus(SpanStatus::internalError());
                    $span->finish();
                }
            }

            while (($span = $this->maybePopSpan()) !== null) {
                if ($span->getEndTimestamp() === null) {
                    $span->setStatus(SpanStatus::internalError());
                    $span->finish();
                }
            }

            $scopeCountKey = $this->contextKey('scope_count');
            $scopeCount = CoroutineContext::get($scopeCountKey, 0);

            while ($scopeCount > 0) {
                SentrySdk::getCurrentHub()->popScope();
                --$scopeCount;
            }

            CoroutineContext::forget($this->contextKey('parent_spans'));
            CoroutineContext::forget($this->contextKey('current_spans'));
            CoroutineContext::forget($this->contextKey('local_spans'));
            CoroutineContext::forget($scopeCountKey);
            CoroutineContext::forget($cleanupKey);
        });
    }
}
