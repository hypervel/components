<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;

/**
 * Own the current coroutine's AI span stack while sharing invocation lookup with its children.
 *
 * @internal
 */
class AiTracingScope implements NonCopyableContext
{
    public const string CONTEXT_KEY = '__sentry.ai_scope';

    /** @var array<string, array{previous: ?Span, scope: Scope}> */
    private array $entries = [];

    /**
     * Create a scope with an independent span stack.
     */
    public function __construct(
        public readonly AiTracingState $state = new AiTracingState,
        public ?AiConversation $conversation = null,
    ) {
    }

    /**
     * Return the current tracing scope without allocating tracking state.
     */
    public static function current(): ?self
    {
        return CoroutineContext::get(self::CONTEXT_KEY);
    }

    /**
     * Return or create tracking state for sampled AI work.
     */
    public static function getOrCreate(): self
    {
        return self::current() ?? CoroutineContext::set(self::CONTEXT_KEY, new self);
    }

    /**
     * Capture the SDK scope used for parent lookup, installation and restoration.
     */
    public static function sentryScope(): Scope
    {
        $scope = null;

        SentrySdk::getCurrentHub()->configureScope(static function (Scope $current) use (&$scope): void {
            $scope = $current;
        });

        /** @var Scope $scope */
        return $scope;
    }

    /**
     * Install a span and retain the scope it changes.
     */
    public function push(Span $span, Scope $scope): void
    {
        $this->entries[(string) $span->getSpanId()] = [
            'previous' => $scope->getSpan(),
            'scope' => $scope,
        ];

        $scope->setSpan($span);
    }

    /**
     * Finish a span and restore its owner when that owner still exists.
     */
    public static function finishSpan(Span $span, ?self $owner, SpanStatus $status): void
    {
        $span->setStatus($status);
        $span->finish();
        $owner?->remove($span);
    }

    /**
     * Remove an entry without overwriting a later span or another coroutine's scope.
     */
    private function remove(Span $span): void
    {
        $key = (string) $span->getSpanId();
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return;
        }

        $isLast = array_key_last($this->entries) === $key;
        unset($this->entries[$key]);

        // An earlier stream may be abandoned while a later stream's spans are still open.
        if (! $isLast) {
            foreach ($this->entries as &$remaining) {
                if ($remaining['previous'] === $span) {
                    $remaining['previous'] = $entry['previous'];
                }
            }
            unset($remaining);
        }

        if ($entry['scope']->getSpan() === $span) {
            $entry['scope']->setSpan($entry['previous']);
        }
    }
}
